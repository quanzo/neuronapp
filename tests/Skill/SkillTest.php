<?php

declare(strict_types=1);

namespace Tests\Skill;

use Amp\Future;
use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\skill\Skill;
use app\modules\neuron\interfaces\ISkill;
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
     * заменяются пустой строкой.
     */
    public function testGetSkillNoParams(): void
    {
        $skill = new Skill('Hello $name');
        $this->assertSame('Hello ', $skill->getSkill());
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
        $skill = new Skill('$a and $b');
        $result = $skill->getSkill(['a' => 'X']);
        $this->assertSame('X and ', $result);
    }

    /**
     * Текст без плейсхолдеров — параметры игнорируются, текст без изменений.
     */
    public function testGetSkillNoPlaceholders(): void
    {
        $skill = new Skill('No params here');
        $result = $skill->getSkill(['x' => 'ignored']);
        $this->assertSame('No params here', $result);
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
        $this->assertSame('default', $skill->getAgentName());
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

        $agentCfg = new \app\modules\neuron\classes\config\ConfigurationAgent();
        $skill->getTool($agentCfg);
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

        $agentCfg = new \app\modules\neuron\classes\config\ConfigurationAgent();
        $skill->getTool($agentCfg);
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
    //  executeFromAgent — возвращает Future
    // ══════════════════════════════════════════════════════════════

    /**
     * executeFromAgent() возвращает Future (без await, чтобы не требовать реальный бэкенд).
     */
    public function testExecuteFromAgentReturnsFuture(): void
    {
        $skill = new Skill('Hello', 'myskill');
        $agentCfg = new ConfigurationAgent();
        $future = $skill->executeFromAgent($agentCfg);
        $this->assertInstanceOf(Future::class, $future);
    }
}
