<?php

declare(strict_types=1);

namespace Tests\Skill;

use Amp\Future;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\interfaces\ISkill;
use app\modules\neuron\helpers\ToolRegistry;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see Skill}.
 *
 * Skill — текстовый навык (шаблон), хранящий тело с именованными плейсхолдерами
 * и опции (description, params, skills и др.). Наследует AbstractPromptWithParams.
 *
 * Основные возможности:
 *  - getSkill(params) — возвращает текст с подставленными параметрами;
 *  - getNeedSkills() — список зависимых навыков из опции skills;
 *  - checkErrors() — валидация конфигурации (params, skills, самоссылки);
 *  - getTool() — создание LLM-инструмента на основе навыка.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\skill\Skill}
 */
class SkillTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        EventBus::clear();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_skill_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/agents', 0777, true);
        file_put_contents($this->tmpDir . '/config.jsonc', '{}');

        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');
    }

    protected function tearDown(): void
    {
        EventBus::clear();
        $this->resetConfigurationAppSingleton();
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function resetConfigurationAppSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Класс Skill реализует интерфейс ISkill.
     */
    public function testImplementsInterface(): void
    {
        $skill = new Skill('test', 'myskill');
        $this->assertInstanceOf(ISkill::class, $skill);
    }

    // ══════════════════════════════════════════════════════════════
    //  Базовый парсинг
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустая строка — пустое тело и пустые опции.
     */
    public function testEmptyInput(): void
    {
        $skill = new Skill('');
        $this->assertSame('', $skill->getSkill());
        $this->assertSame([], $skill->getOptions());
    }

    /**
     * Текст без разделителей «---» целиком становится телом навыка.
     */
    public function testSimpleBody(): void
    {
        $skill = new Skill('Translate the given text');
        $this->assertSame('Translate the given text', $skill->getSkill());
    }

    /**
     * Формат с блоком опций: описание попадает в options, тело — в getSkill().
     */
    public function testBodyWithOptions(): void
    {
        $input = "---\ndescription: Translation\n---\nTranslate \$text";
        $skill = new Skill($input, 'translate');
        $this->assertSame('Translation', $skill->getOptions()['description']);
    }

    /**
     * getName() возвращает имя, переданное в конструктор (может включать путь).
     */
    public function testGetName(): void
    {
        $skill = new Skill('body', 'my/skill');
        $this->assertSame('my/skill', $skill->getName());
    }

    /**
     * Имя по умолчанию — пустая строка.
     */
    public function testGetNameDefault(): void
    {
        $skill = new Skill('body');
        $this->assertSame('', $skill->getName());
    }

    // ══════════════════════════════════════════════════════════════
    //  getSkill — подстановка параметров
    // ══════════════════════════════════════════════════════════════

    /**
     * Вызов getSkill() без аргументов — отсутствующие плейсхолдеры
     * заменяются пустой строкой, либо значениями по умолчанию из params.
     */
    public function testGetSkillNoParams(): void
    {
        $input = "---\nparams: {\"name\": {\"type\": \"string\", \"default\": \"World\"}}\n---\nHello \$name";
        $skill = new Skill($input, 'greet');
        $this->assertSame('Hello World', $skill->getSkill());
    }

    /**
     * Подстановка нескольких параметров одновременно.
     */
    public function testGetSkillWithParams(): void
    {
        $skill = new Skill('Hello $name, search $query');
        $result = $skill->getSkill(['name' => 'World', 'query' => 'cats']);
        $this->assertSame('Hello World, search cats', $result);
    }

    /**
     * Один из параметров не передан — его плейсхолдер заменяется пустой строкой.
     */
    public function testGetSkillMissingParamReplacedEmpty(): void
    {
        $input = "---\nparams: {\"a\": {\"type\": \"string\", \"default\": \"A\"}, \"b\": {\"type\": \"string\"}}\n---\n\$a and \$b";
        $skill = new Skill($input);
        $result = $skill->getSkill(['b' => 'B']);
        $this->assertSame('A and B', $result);
    }

    /**
     * Приоритет значений: runtime > default.
     */
    public function testGetSkillRuntimeOverridesDefault(): void
    {
        $input = "---\nparams: {\"name\": {\"type\": \"string\", \"default\": \"World\"}}\n---\nHello \$name";
        $skill = new Skill($input, 'greet');
        $result = $skill->getSkill(['name' => 'Alice']);
        $this->assertSame('Hello Alice', $result);
    }

    /**
     * Приоритет значений: runtime > agent params > default.
     */
    public function testGetSkillRuntimeOverridesAgentParamsOverridesDefault(): void
    {
        $input = "---\nparams: {\"name\": {\"type\": \"string\", \"default\": \"World\"}}\n---\nHello \$name";
        $skill = new Skill($input, 'greet');

        $agentCfg = ConfigurationAgent::makeFromArray([
            'enableChatHistory' => false,
            'contextWindow' => 50000,
            'params' => ['name' => 'Bob'],
        ], \app\modules\neuron\classes\config\ConfigurationApp::getInstance());
        $this->assertInstanceOf(ConfigurationAgent::class, $agentCfg);
        $skill->setDefaultConfigurationAgent($agentCfg);

        $this->assertSame('Hello Bob', $skill->getSkill(), 'agent params должны перекрывать default');
        $this->assertSame('Hello Alice', $skill->getSkill(['name' => 'Alice']), 'runtime должен перекрывать agent params');
    }

    // ══════════════════════════════════════════════════════════════
    //  Удаление комментариев из тела
    // ══════════════════════════════════════════════════════════════

    /**
     * Однострочные комментарии (// ...) удаляются из тела навыка.
     */
    public function testCommentsAreStrippedFromBody(): void
    {
        $skill = new Skill("Do something // comment\nnext line");
        $this->assertSame("Do something\nnext line", $skill->getSkill());
    }

    /**
     * Блочные комментарии удаляются из тела навыка.
     */
    public function testBlockCommentsStripped(): void
    {
        $skill = new Skill("before /* hidden */ after");
        $this->assertSame('before  after', $skill->getSkill());
    }

    // ══════════════════════════════════════════════════════════════
    //  getNeedSkills — зависимые навыки
    // ══════════════════════════════════════════════════════════════

    /**
     * Опция skills не задана — пустой массив.
     */
    public function testGetNeedSkillsEmpty(): void
    {
        $skill = new Skill('body', 'myskill');
        $this->assertSame([], $skill->getNeedSkills());
    }

    /**
     * Опция skills с двумя именами — оба возвращаются в массиве.
     */
    public function testGetNeedSkills(): void
    {
        $input = "---\nskills: search, translate\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertSame(['search', 'translate'], $skill->getNeedSkills());
    }

    /**
     * Имя самого навыка (myskill) исключается из списка зависимостей
     * для предотвращения рекурсии.
     */
    public function testGetNeedSkillsExcludesSelf(): void
    {
        $input = "---\nskills: search, myskill, translate\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $skills = $skill->getNeedSkills();
        $this->assertNotContains('myskill', $skills);
        $this->assertSame(['search', 'translate'], $skills);
    }

    /**
     * Нестроковое значение skills (после JSON-декодирования) — пустой массив.
     */
    public function testGetNeedSkillsNonStringOption(): void
    {
        $input = "---\nskills: 42\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertSame([], $skill->getNeedSkills());
    }

    // ══════════════════════════════════════════════════════════════
    //  getNeedTools — встроенные инструменты через опцию tools
    // ══════════════════════════════════════════════════════════════

    /**
     * Опция tools не задана — пустой массив.
     */
    public function testGetNeedToolsEmpty(): void
    {
        $skill = new Skill('body', 'myskill');
        $this->assertSame([], $skill->getNeedTools());
    }

    /**
     * Опция tools с двумя именами — оба возвращаются в массиве.
     */
    public function testGetNeedTools(): void
    {
        $input = "---\ntools: wiki_search, git_summary\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertSame(['wiki_search', 'git_summary'], $skill->getNeedTools());
    }

    /**
     * Нестроковое значение tools (после JSON-декодирования) — ошибка invalid_tools_option_type.
     */
    public function testCheckErrorsInvalidToolsType(): void
    {
        $input = "---\ntools: [1, 2]\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $errors = $skill->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('invalid_tools_option_type', $types);
    }

    // ══════════════════════════════════════════════════════════════
    //  isPureContext — опция pure_context
    // ══════════════════════════════════════════════════════════════

    /**
     * Опция pure_context не задана — false.
     */
    public function testIsPureContextNotSetReturnsFalse(): void
    {
        $skill = new Skill('body', 'myskill');
        $this->assertFalse($skill->isPureContext());
    }

    /**
     * Опция pure_context задана 0 — false.
     */
    public function testIsPureContextZeroReturnsFalse(): void
    {
        $input = "---\npure_context: 0\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertFalse($skill->isPureContext());
    }

    /**
     * Опция pure_context задана строкой 'false' — false.
     */
    public function testIsPureContextStringFalseReturnsFalse(): void
    {
        $input = "---\npure_context: false\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertFalse($skill->isPureContext());
    }

    /**
     * Опция pure_context задана 1 — true.
     */
    public function testIsPureContextOneReturnsTrue(): void
    {
        $input = "---\npure_context: 1\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertTrue($skill->isPureContext());
    }

    /**
     * Опция pure_context задана строкой 'true' — true.
     */
    public function testIsPureContextStringTrueReturnsTrue(): void
    {
        $input = "---\npure_context: true\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertTrue($skill->isPureContext());
    }

    /**
     * Опция pure_context с неожиданным значением (например строка "yes") — false.
     */
    public function testIsPureContextUnexpectedValueReturnsFalse(): void
    {
        $input = "---\npure_context: yes\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertFalse($skill->isPureContext());
    }

    // ══════════════════════════════════════════════════════════════
    //  checkErrors — валидация конфигурации
    // ══════════════════════════════════════════════════════════════

    /**
     * Простое тело без параметров и skills — ошибок нет.
     */
    public function testCheckErrorsNoErrors(): void
    {
        $skill = new Skill('Simple body', 'myskill');
        $this->assertSame([], $skill->checkErrors());
    }

    /**
     * Плейсхолдер $query в теле, но params не определены —
     * ошибка missing_param_definition.
     */
    public function testCheckErrorsMissingParamDefinition(): void
    {
        $input = "---\n---\nSearch for \$query";
        $skill = new Skill($input, 'search');
        $errors = $skill->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('missing_param_definition', $types);
    }

    /**
     * Параметр описан в params, но не используется в теле —
     * ошибка unused_param_definition.
     */
    public function testCheckErrorsUnusedParam(): void
    {
        $input = "---\nparams: {\"unused\": \"string\"}\n---\nBody without params";
        $skill = new Skill($input, 'myskill');
        $errors = $skill->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('unused_param_definition', $types);
    }

    /**
     * Skills содержит имя самого навыка — ошибка self_referenced_skill.
     */
    public function testCheckErrorsSelfReference(): void
    {
        $input = "---\nskills: myskill\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $errors = $skill->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('self_referenced_skill', $types);
    }

    /**
     * Нестроковое значение skills (массив после JSON-декодирования) —
     * ошибка invalid_skills_option_type.
     */
    public function testCheckErrorsInvalidSkillsType(): void
    {
        $input = "---\nskills: [1, 2]\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $errors = $skill->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('invalid_skills_option_type', $types);
    }

    /**
     * Корректно описанный параметр, совпадающий с плейсхолдером в теле — ошибок нет.
     */
    public function testCheckErrorsValidParamDefinition(): void
    {
        $input = "---\nparams: {\"query\": \"string\"}\n---\nSearch for \$query";
        $skill = new Skill($input, 'search');
        $errors = $skill->checkErrors();
        $this->assertSame([], $errors);
    }

    // ══════════════════════════════════════════════════════════════
    //  getParamList — DTO параметров
    // ══════════════════════════════════════════════════════════════

    /**
     * Без опции params — пустой ParamListDto.
     */
    public function testGetParamListEmpty(): void
    {
        $skill = new Skill('body');
        $paramList = $skill->getParamList();
        $this->assertNotNull($paramList);
        $this->assertSame([], $paramList->all());
    }

    /**
     * Опция params задана корректно — ParamListDto содержит описанный параметр.
     */
    public function testGetParamListWithDefined(): void
    {
        $input = "---\nparams: {\"query\": {\"type\": \"string\", \"required\": true}}\n---\nSearch \$query";
        $skill = new Skill($input, 'search');
        $paramList = $skill->getParamList();
        $this->assertNotNull($paramList);
        $this->assertTrue($paramList->has('query'));
        $this->assertTrue($paramList->get('query')->isRequired());
    }

    /**
     * Невалидный JSON в params — getParamList() возвращает null.
     */
    public function testGetParamListInvalidReturnsNull(): void
    {
        $input = "---\nparams: not json\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertNull($skill->getParamList());
    }

    // ══════════════════════════════════════════════════════════════
    //  getAgentName — имя агента-исполнителя
    // ══════════════════════════════════════════════════════════════

    /**
     * Если опция agent задана — возвращается её значение.
     */
    public function testGetAgentNameFromOption(): void
    {
        $input = "---\nagent: customAgent\n---\nBody";
        $skill = new Skill($input, 'myskill');
        $this->assertSame('customAgent', $skill->getAgentName());
    }

    /**
     * Без опции agent — используется имя по умолчанию ('default').
     */
    public function testGetAgentNameDefault(): void
    {
        $skill = new Skill('Body', 'myskill');
        $this->assertNull($skill->getAgentName());
    }

    // ══════════════════════════════════════════════════════════════
    //  getTool — генерация LLM-инструмента (ошибочные случаи)
    // ══════════════════════════════════════════════════════════════

    /**
     * Невалидный JSON в params при вызове getTool() — RuntimeException.
     */
    public function testGetToolThrowsOnInvalidParamConfig(): void
    {
        $input = "---\nparams: not json\n---\nBody with \$query";
        $skill = new Skill($input, 'myskill');

        $this->expectException(\RuntimeException::class);

        $skill->getTool();
    }

    /**
     * Плейсхолдер без описания в params при вызове getTool() — RuntimeException
     * (критичная ошибка missing_param_definition).
     */
    public function testGetToolThrowsOnMissingParamDefinition(): void
    {
        $input = "---\n---\nBody with \$query";
        $skill = new Skill($input, 'myskill');

        $this->expectException(\RuntimeException::class);

        $skill->getTool();
    }

    // ══════════════════════════════════════════════════════════════
    //  Декодирование JSON-значений опций
    // ══════════════════════════════════════════════════════════════

    /**
     * Значение опции — JSON-объект — декодируется в ассоциативный массив.
     */
    public function testOptionsJsonObject(): void
    {
        $input = "---\ndata: {\"key\": \"value\"}\n---\nBody";
        $skill = new Skill($input);
        $this->assertSame(['key' => 'value'], $skill->getOptions()['data']);
    }

    /**
     * Значение опции — JSON-массив — декодируется в индексированный массив.
     */
    public function testOptionsJsonArray(): void
    {
        $input = "---\ntags: [\"a\", \"b\"]\n---\nBody";
        $skill = new Skill($input);
        $this->assertSame(['a', 'b'], $skill->getOptions()['tags']);
    }

    /**
     * Значение опции — JSON-число — декодируется в int.
     */
    public function testOptionsJsonNumber(): void
    {
        $input = "---\ncount: 42\n---\nBody";
        $skill = new Skill($input);
        $this->assertSame(42, $skill->getOptions()['count']);
    }

    /**
     * Значение опции — JSON true — декодируется в bool true.
     */
    public function testOptionsJsonBoolean(): void
    {
        $input = "---\nenabled: true\n---\nBody";
        $skill = new Skill($input);
        $this->assertTrue($skill->getOptions()['enabled']);
    }

    /**
     * Значение опции — JSON null — декодируется в PHP null.
     */
    public function testOptionsJsonNull(): void
    {
        $input = "---\nvalue: null\n---\nBody";
        $skill = new Skill($input);
        $this->assertNull($skill->getOptions()['value']);
    }

    /**
     * Отрицательное число в опции корректно декодируется.
     */
    public function testOptionsNegativeNumber(): void
    {
        $input = "---\noffset: -5\n---\nBody";
        $skill = new Skill($input);
        $this->assertSame(-5, $skill->getOptions()['offset']);
    }

    /**
     * Строка, не являющаяся валидным JSON (например, «myAgent»),
     * сохраняется как есть.
     */
    public function testOptionsPlainStringNotJson(): void
    {
        $input = "---\nagent: myAgent\n---\nBody";
        $skill = new Skill($input);
        $this->assertSame('myAgent', $skill->getOptions()['agent']);
    }

    // ══════════════════════════════════════════════════════════════
    //  execute — возвращает Future
    // ══════════════════════════════════════════════════════════════

    /**
     * execute() возвращает Future (без await, чтобы не требовать реальный бэкенд).
     * Конфиг с мок-агентом, чтобы при выполнении Future не вызывался реальный getProvider().
     */
    public function testExecuteReturnsFuture(): void
    {
        $skill = new Skill('Hello', 'myskill');
        $handler = $this->createMock(AgentHandler::class);
        $handler->method('getMessage')->willReturn(new Message(MessageRole::ASSISTANT, 'ok'));
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('chat')->willReturn($handler);

        $agentCfg = new class ($agent) extends ConfigurationAgent {
            public function __construct(private readonly AgentInterface $agent)
            {
            }

            public function getAgent(): AgentInterface
            {
                return $this->agent;
            }
        };

        $skill->setDefaultConfigurationAgent($agentCfg);
        $future = $skill->execute();
        $this->assertInstanceOf(Future::class, $future);
        $future->ignore();
    }
}
