<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\BashCmdTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Тесты для {@see BashCmdTool}.
 *
 * Проверяют корректность работы инструмента выполнения предопределённой bash-команды
 * с подстановкой плейсхолдеров. Используются только безопасные команды (echo, printf, pwd, date).
 *
 * Тестируемые сценарии:
 * - выполнение команды без плейсхолдеров
 * - подстановка одного плейсхолдера
 * - подстановка нескольких плейсхолдеров
 * - подстановка при отсутствующем значении (пустая строка)
 * - работа с integer-параметрами
 * - получение шаблона команды через getter
 * - извлечение списка плейсхолдеров
 * - валидация соответствия плейсхолдеров и свойств (полное совпадение, пропущенное свойство, лишнее свойство)
 * - делегирование настройки workingDirectory внутреннему BashTool
 * - делегирование blockedPatterns (блокировка по сформированной команде)
 * - делегирование allowedPatterns
 * - делегирование переменных окружения (env)
 * - команда без плейсхолдеров (шаблон == финальная команда)
 * - шаблон с пустым значением плейсхолдера (нет аргумента от LLM)
 * - множественное использование одного плейсхолдера в шаблоне
 */
final class BashCmdToolTest extends TestCase
{
    /**
     * Путь к временной директории, создаваемой для каждого теста.
     */
    private string $tempDir;

    /**
     * Создаёт уникальную временную директорию перед каждым тестом.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bash_cmd_tool_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    /**
     * Удаляет временную директорию и всё её содержимое после каждого теста.
     */
    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * Проверяет выполнение простого шаблона без плейсхолдеров.
     *
     * Команда `echo "static output"` не содержит плейсхолдеров,
     * поэтому должна выполниться без изменений.
     */
    public function testExecutesStaticCommandWithoutPlaceholders(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo "static output"',
            name: 'test_static',
            description: 'Test static command',
        );

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('static output', $data['stdout']);
        $this->assertFalse($data['timedOut']);
    }

    /**
     * Проверяет подстановку одного строкового плейсхолдера.
     *
     * Шаблон `echo "Hello, $name!"` при передаче name=World
     * должен выполнить `echo "Hello, World!"`.
     */
    public function testSubstitutesSinglePlaceholder(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo "Hello, $name!"',
            name: 'greet',
            description: 'Greet by name',
        );
        $tool->addProperty(new ToolProperty('name', PropertyType::STRING, 'Имя', true));

        $json = $tool->__invoke(name: 'World');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('Hello, World!', $data['stdout']);
    }

    /**
     * Проверяет подстановку нескольких плейсхолдеров в одном шаблоне.
     *
     * Шаблон `printf '%s %s\n' $first $second` при передаче first=foo, second=bar
     * должен вывести "foo bar".
     */
    public function testSubstitutesMultiplePlaceholders(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'printf "%s %s\n" $first $second',
            name: 'multi',
            description: 'Multi-placeholder test',
        );
        $tool->addProperty(new ToolProperty('first', PropertyType::STRING, 'Первый аргумент', true));
        $tool->addProperty(new ToolProperty('second', PropertyType::STRING, 'Второй аргумент', true));

        $json = $tool->__invoke(first: 'foo', second: 'bar');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('foo bar', $data['stdout']);
    }

    /**
     * Проверяет, что отсутствующий аргумент заменяется пустой строкой.
     *
     * Шаблон `echo "value=$val"` при вызове без аргументов
     * должен выполнить `echo "value="`.
     */
    public function testMissingPlaceholderReplacedWithEmptyString(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo "value=$val"',
            name: 'missing_test',
            description: 'Missing placeholder test',
        );
        $tool->addProperty(new ToolProperty('val', PropertyType::STRING, 'Значение', false));

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('value=', $data['stdout']);
    }

    /**
     * Проверяет подстановку числового (integer) значения в плейсхолдер.
     *
     * Шаблон `echo "count=$count"` при count=42 должен вывести "count=42".
     */
    public function testSubstitutesIntegerParameter(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo "count=$count"',
            name: 'int_test',
            description: 'Integer placeholder test',
        );
        $tool->addProperty(new ToolProperty('count', PropertyType::INTEGER, 'Количество', true));

        $json = $tool->__invoke(count: 42);
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('count=42', $data['stdout']);
    }

    /**
     * Проверяет, что getCommandTemplate() возвращает исходный шаблон.
     */
    public function testGetCommandTemplateReturnsOriginal(): void
    {
        $template = 'echo $msg';
        $tool = new BashCmdTool(commandTemplate: $template);

        $this->assertSame($template, $tool->getCommandTemplate());
    }

    /**
     * Проверяет, что getPlaceholders() корректно извлекает имена из шаблона.
     *
     * Шаблон с двумя плейсхолдерами должен вернуть массив из двух имён.
     */
    public function testGetPlaceholdersExtractsNames(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $alpha $beta');

        $placeholders = $tool->getPlaceholders();

        $this->assertCount(2, $placeholders);
        $this->assertContains('alpha', $placeholders);
        $this->assertContains('beta', $placeholders);
    }

    /**
     * Проверяет, что getPlaceholders() возвращает пустой массив для шаблона без плейсхолдеров.
     */
    public function testGetPlaceholdersReturnsEmptyForStaticTemplate(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo hello');

        $this->assertSame([], $tool->getPlaceholders());
    }

    /**
     * Проверяет успешную валидацию при полном соответствии свойств и плейсхолдеров.
     *
     * Если каждый плейсхолдер имеет своё свойство и наоборот,
     * validatePlaceholders() должен вернуть пустой массив.
     */
    public function testValidatePlaceholdersReturnsEmptyOnMatch(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $name $age');
        $tool->addProperty(new ToolProperty('name', PropertyType::STRING, 'Имя', true));
        $tool->addProperty(new ToolProperty('age', PropertyType::INTEGER, 'Возраст', true));

        $errors = $tool->validatePlaceholders();

        $this->assertSame([], $errors);
    }

    /**
     * Проверяет, что валидация обнаруживает плейсхолдер без соответствующего свойства.
     *
     * Шаблон содержит $host, но свойство не добавлено — ожидается ошибка 'missing_property'.
     */
    public function testValidatePlaceholdersDetectsMissingProperty(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $host $port');
        $tool->addProperty(new ToolProperty('port', PropertyType::INTEGER, 'Порт', true));

        $errors = $tool->validatePlaceholders();

        $this->assertCount(1, $errors);
        $this->assertSame('missing_property', $errors[0]['type']);
        $this->assertSame('host', $errors[0]['param']);
    }

    /**
     * Проверяет, что валидация обнаруживает свойство без плейсхолдера.
     *
     * Свойство 'extra' добавлено, но в шаблоне нет $extra — ожидается ошибка 'unused_property'.
     */
    public function testValidatePlaceholdersDetectsUnusedProperty(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $name');
        $tool->addProperty(new ToolProperty('name', PropertyType::STRING, 'Имя', true));
        $tool->addProperty(new ToolProperty('extra', PropertyType::STRING, 'Лишнее', false));

        $errors = $tool->validatePlaceholders();

        $this->assertCount(1, $errors);
        $this->assertSame('unused_property', $errors[0]['type']);
        $this->assertSame('extra', $errors[0]['param']);
    }

    /**
     * Проверяет, что валидация одновременно находит и пропущенное, и лишнее свойство.
     */
    public function testValidatePlaceholdersBothMissingAndUnused(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $needed');
        $tool->addProperty(new ToolProperty('other', PropertyType::STRING, 'Другое', false));

        $errors = $tool->validatePlaceholders();

        $this->assertCount(2, $errors);

        $types = array_column($errors, 'type');
        $this->assertContains('missing_property', $types);
        $this->assertContains('unused_property', $types);
    }

    /**
     * Проверяет делегирование workingDirectory внутреннему BashTool.
     *
     * Команда `pwd` в заданной директории должна вернуть путь к ней.
     */
    public function testWorkingDirectoryDelegation(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'pwd',
            workingDirectory: $this->tempDir,
        );

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString($this->tempDir, $data['stdout']);
    }

    /**
     * Проверяет делегирование workingDirectory через сеттер.
     *
     * setWorkingDirectory() должен корректно передать значение исполнителю.
     */
    public function testSetWorkingDirectoryViaSetter(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'pwd');
        $tool->setWorkingDirectory($this->tempDir);

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertStringContainsString($this->tempDir, $data['stdout']);
    }

    /**
     * Проверяет, что blockedPatterns блокируют сформированную команду.
     *
     * Шаблон `echo $msg` при msg=danger должен сформировать `echo danger`,
     * что совпадает с блокирующим шаблоном /danger/.
     */
    public function testBlockedPatternsAppliedToRenderedCommand(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo $msg',
            blockedPatterns: ['/danger/'],
        );
        $tool->addProperty(new ToolProperty('msg', PropertyType::STRING, 'Сообщение', true));

        $json = $tool->__invoke(msg: 'danger');
        $data = json_decode($json, true);

        $this->assertSame(-1, $data['exitCode']);
        $this->assertStringContainsString('заблокирована', $data['stderr']);
    }

    /**
     * Проверяет, что blockedPatterns можно обновить через сеттер.
     */
    public function testSetBlockedPatternsViaSetter(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $msg');
        $tool->addProperty(new ToolProperty('msg', PropertyType::STRING, 'Сообщение', true));

        $tool->setBlockedPatterns(['/forbidden/']);

        $json = $tool->__invoke(msg: 'forbidden');
        $data = json_decode($json, true);

        $this->assertSame(-1, $data['exitCode']);
    }

    /**
     * Проверяет, что allowedPatterns ограничивают сформированную команду.
     *
     * Только команды, начинающиеся с echo, должны быть разрешены.
     * Шаблон `echo $val` с val=hello проходит, а шаблон `ls $dir` — нет.
     */
    public function testAllowedPatternsAppliedToRenderedCommand(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo $val',
            allowedPatterns: ['/^echo\b/'],
        );
        $tool->addProperty(new ToolProperty('val', PropertyType::STRING, 'Значение', true));

        $json = $tool->__invoke(val: 'hello');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('hello', $data['stdout']);
    }

    /**
     * Проверяет, что allowedPatterns можно обновить через сеттер.
     */
    public function testSetAllowedPatternsViaSetter(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo test');
        $tool->setAllowedPatterns(['/^printf\b/']);

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertSame(-1, $data['exitCode']);
        $this->assertStringContainsString('не соответствует', $data['stderr']);
    }

    /**
     * Проверяет передачу переменных окружения через конструктор.
     *
     * Используется `printenv`, чтобы избежать конфликта между bash-переменными
     * и плейсхолдерами (PlaceholderHelper разбирает только [a-zA-Z]+).
     */
    public function testEnvVarsPassedViaConstructor(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'printenv MY_VAR',
            env: ['MY_VAR' => 'from_env'],
        );

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('from_env', $data['stdout']);
    }

    /**
     * Проверяет передачу переменных окружения через сеттер.
     *
     * Используется `printenv` для прямого чтения переменной окружения.
     */
    public function testSetEnvViaSetter(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'printenv CUSTOM_VAR');
        $tool->setEnv(['CUSTOM_VAR' => 'set_via_setter']);

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertStringContainsString('set_via_setter', $data['stdout']);
    }

    /**
     * Проверяет работу сеттера setDefaultTimeout.
     *
     * Устанавливаем таймаут в 1 секунду и запускаем sleep 30 — должен быть таймаут.
     */
    public function testSetDefaultTimeoutViaSetter(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'sleep 30');
        $tool->setDefaultTimeout(1);

        $json = $tool->__invoke();
        $data = json_decode($json, true);

        $this->assertTrue($data['timedOut']);
        $this->assertSame(-1, $data['exitCode']);
    }

    /**
     * Проверяет работу сеттера setMaxOutputSize.
     *
     * Генерируется длинный вывод, а maxOutputSize ограничен 30 байтами.
     */
    public function testSetMaxOutputSizeViaSetter(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $msg');
        $tool->addProperty(new ToolProperty('msg', PropertyType::STRING, 'Сообщение', true));
        $tool->setMaxOutputSize(30);

        $longMsg = str_repeat('A', 200);
        $json = $tool->__invoke(msg: $longMsg);
        $data = json_decode($json, true);

        $this->assertLessThanOrEqual(80, strlen($data['stdout']));
    }

    /**
     * Проверяет, что один и тот же плейсхолдер используется в нескольких местах шаблона.
     *
     * Шаблон `echo "$word $word"` при word=test должен вывести "test test".
     */
    public function testDuplicatePlaceholderInTemplate(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo "$word $word"',
            name: 'dup_test',
            description: 'Duplicate placeholder test',
        );
        $tool->addProperty(new ToolProperty('word', PropertyType::STRING, 'Слово', true));

        $json = $tool->__invoke(word: 'test');
        $data = json_decode($json, true);

        $this->assertSame(0, $data['exitCode']);
        $this->assertStringContainsString('test test', $data['stdout']);
    }

    /**
     * Проверяет, что getPlaceholders() возвращает уникальные имена
     * даже при повторном использовании плейсхолдера в шаблоне.
     */
    public function testGetPlaceholdersReturnsUniqueNames(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $x $x $x');

        $placeholders = $tool->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $this->assertSame('x', $placeholders[0]);
    }

    /**
     * Проверяет, что имя и описание инструмента корректно задаются через конструктор.
     */
    public function testNameAndDescriptionSetCorrectly(): void
    {
        $tool = new BashCmdTool(
            commandTemplate: 'echo ok',
            name: 'my_tool',
            description: 'Мой тестовый инструмент',
        );

        $this->assertSame('my_tool', $tool->getName());
        $this->assertSame('Мой тестовый инструмент', $tool->getDescription());
    }

    /**
     * Проверяет значения по умолчанию для имени и описания.
     */
    public function testDefaultNameAndDescription(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo ok');

        $this->assertSame('bash_cmd', $tool->getName());
        $this->assertSame('Выполнение предопределённой shell-команды с параметрами.', $tool->getDescription());
    }

    /**
     * Проверяет корректность результата: JSON содержит ключ 'command'
     * с сформированной (после подстановки) командой.
     */
    public function testResultContainsRenderedCommand(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo $greeting $target');
        $tool->addProperty(new ToolProperty('greeting', PropertyType::STRING, 'Приветствие', true));
        $tool->addProperty(new ToolProperty('target', PropertyType::STRING, 'Кому', true));

        $json = $tool->__invoke(greeting: 'Hi', target: 'there');
        $data = json_decode($json, true);

        $this->assertSame('echo Hi there', $data['command']);
    }

    /**
     * Проверяет, что цепочка сеттеров возвращает текущий экземпляр (fluent interface).
     */
    public function testSettersReturnSelf(): void
    {
        $tool = new BashCmdTool(commandTemplate: 'echo ok');

        $this->assertSame($tool, $tool->setDefaultTimeout(10));
        $this->assertSame($tool, $tool->setMaxOutputSize(1024));
        $this->assertSame($tool, $tool->setWorkingDirectory('/tmp'));
        $this->assertSame($tool, $tool->setAllowedPatterns([]));
        $this->assertSame($tool, $tool->setBlockedPatterns([]));
        $this->assertSame($tool, $tool->setEnv([]));
    }

    /**
     * Рекурсивно удаляет директорию и всё её содержимое.
     *
     * @param string $dir Путь к директории для удаления
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
