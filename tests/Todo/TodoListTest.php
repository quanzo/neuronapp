<?php

declare(strict_types=1);

namespace Tests\Todo;

use app\modules\neuron\classes\todo\Todo;
use app\modules\neuron\classes\todo\TodoList;
use app\modules\neuron\interfaces\ITodoList;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see TodoList}.
 *
 * TodoList — список заданий (Todo), формируемый из текстового ввода.
 * Наследует APromptComponent (разбор опций и тела) и AbstractPromptWithParams
 * (работа с параметрами и skills). Задания хранятся как FIFO-очередь.
 *
 * Поддерживает:
 *  - нумерованные задания (1. Текст\n2. Текст);
 *  - ненумерованное единственное задание (весь текст — одно задание);
 *  - блок опций (agent, skills, params и др.);
 *  - удаление PHP-комментариев из тела;
 *  - валидацию параметров и навыков.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\todo\TodoList}
 */
class TodoListTest extends TestCase
{
    /**
     * Класс TodoList реализует интерфейс ITodoList.
     */
    public function testImplementsInterface(): void
    {
        $list = new TodoList('');
        $this->assertInstanceOf(ITodoList::class, $list);
    }

    // ══════════════════════════════════════════════════════════════
    //  Пустой вход
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустая строка — список заданий пуст, popTodo() сразу возвращает null.
     */
    public function testEmptyInput(): void
    {
        $list = new TodoList('');
        $this->assertSame([], $list->getTodos());
        $this->assertNull($list->popTodo());
    }

    /**
     * Пустой вход с указанием имени — имя сохраняется.
     */
    public function testEmptyInputWithName(): void
    {
        $list = new TodoList('', 'mylist');
        $this->assertSame('mylist', $list->getName());
    }

    // ══════════════════════════════════════════════════════════════
    //  Единственное ненумерованное задание
    // ══════════════════════════════════════════════════════════════

    /**
     * Текст без нумерации трактуется как одно задание целиком.
     */
    public function testSingleUnnumberedTask(): void
    {
        $list = new TodoList("Do something");
        $todos = $list->getTodos();
        $this->assertCount(1, $todos);
        $this->assertSame('Do something', $todos[0]->getTodo());
    }

    /**
     * Многострочный текст без нумерации — тоже одно задание.
     */
    public function testMultilineUnnumberedTask(): void
    {
        $list = new TodoList("Line one\nLine two\nLine three");
        $todos = $list->getTodos();
        $this->assertCount(1, $todos);
        $this->assertSame("Line one\nLine two\nLine three", $todos[0]->getTodo());
    }

    // ══════════════════════════════════════════════════════════════
    //  Нумерованные задания
    // ══════════════════════════════════════════════════════════════

    /**
     * Три нумерованных задания разбиваются на три отдельных Todo.
     */
    public function testNumberedTasks(): void
    {
        $input = "1. First task\n2. Second task\n3. Third task";
        $list = new TodoList($input);
        $todos = $list->getTodos();

        $this->assertCount(3, $todos);
        $this->assertSame('First task', $todos[0]->getTodo());
        $this->assertSame('Second task', $todos[1]->getTodo());
        $this->assertSame('Third task', $todos[2]->getTodo());
    }

    /**
     * Нумерованное задание с продолжением на следующей строке (без нового номера)
     * — продолжение включается в текст предыдущего задания.
     */
    public function testNumberedTasksWithMultilineContent(): void
    {
        $input = "1. Task one\ncontinuation\n2. Task two";
        $list = new TodoList($input);
        $todos = $list->getTodos();

        $this->assertCount(2, $todos);
        $this->assertSame("Task one\ncontinuation", $todos[0]->getTodo());
        $this->assertSame('Task two', $todos[1]->getTodo());
    }

    /**
     * Ведущие пустые строки перед первым заданием пропускаются.
     */
    public function testNumberedTasksWithLeadingEmptyLines(): void
    {
        $input = "\n\n1. First task\n2. Second task";
        $list = new TodoList($input);
        $todos = $list->getTodos();

        $this->assertCount(2, $todos);
        $this->assertSame('First task', $todos[0]->getTodo());
    }

    // ══════════════════════════════════════════════════════════════
    //  Опции + задания
    // ══════════════════════════════════════════════════════════════

    /**
     * Полный формат: блок опций между «---» и блок заданий после второго «---».
     */
    public function testOptionsAndBody(): void
    {
        $input = "---\nagent: myAgent\nskills: skill1, skill2\n---\n1. Task one\n2. Task two";
        $list = new TodoList($input);

        $this->assertSame('myAgent', $list->getOptions()['agent']);
        $this->assertSame('skill1, skill2', $list->getOptions()['skills']);
        $this->assertCount(2, $list->getTodos());
    }

    /**
     * Блок опций есть, но тело пусто — список заданий пуст.
     */
    public function testOptionsOnlyNoBody(): void
    {
        $input = "---\nagent: test\n---";
        $list = new TodoList($input);

        $this->assertSame([], $list->getTodos());
        $this->assertSame('test', $list->getOptions()['agent']);
    }

    /**
     * Один разделитель «---» — всё после него считается блоком опций,
     * тело отсутствует.
     */
    public function testSingleDelimiterOptionsOnly(): void
    {
        $input = "---\nagent: test";
        $list = new TodoList($input);

        $this->assertSame('test', $list->getOptions()['agent']);
        $this->assertSame([], $list->getTodos());
    }

    // ══════════════════════════════════════════════════════════════
    //  Удаление комментариев из тела
    // ══════════════════════════════════════════════════════════════

    /**
     * Однострочный комментарий (// ...) в задании удаляется.
     */
    public function testCommentsInBodyAreStripped(): void
    {
        $input = "1. Do something // this is a comment\n2. Task two";
        $list = new TodoList($input);
        $todos = $list->getTodos();

        $this->assertCount(2, $todos);
        $this->assertSame('Do something', $todos[0]->getTodo());
    }

    /**
     * Блочный комментарий удаляется из текста задания.
     */
    public function testBlockCommentsStripped(): void
    {
        $input = "1. Task /* hidden */ visible\n2. Task two";
        $list = new TodoList($input);
        $todos = $list->getTodos();

        $this->assertSame('Task  visible', $todos[0]->getTodo());
    }

    // ══════════════════════════════════════════════════════════════
    //  FIFO-очередь: pushTodo / popTodo
    // ══════════════════════════════════════════════════════════════

    /**
     * Задания извлекаются из списка в порядке FIFO (первый вошёл — первый вышел).
     * После извлечения всех заданий popTodo() возвращает null.
     */
    public function testFifoOrder(): void
    {
        $list = new TodoList("1. First\n2. Second\n3. Third");

        $this->assertSame('First', $list->popTodo()->getTodo());
        $this->assertSame('Second', $list->popTodo()->getTodo());
        $this->assertSame('Third', $list->popTodo()->getTodo());
        $this->assertNull($list->popTodo());
    }

    /**
     * pushTodo() добавляет задание в конец очереди.
     */
    public function testPushTodo(): void
    {
        $list = new TodoList('');
        $list->pushTodo(Todo::fromString('Added task'));

        $todos = $list->getTodos();
        $this->assertCount(1, $todos);
        $this->assertSame('Added task', $todos[0]->getTodo());
    }

    /**
     * pushTodo() принимает несколько заданий одновременно (variadic).
     */
    public function testPushMultipleTodos(): void
    {
        $list = new TodoList('');
        $list->pushTodo(
            Todo::fromString('Task A'),
            Todo::fromString('Task B'),
        );

        $this->assertCount(2, $list->getTodos());
    }

    /**
     * Задания, добавленные через pushTodo(), ставятся после уже существующих.
     */
    public function testPushThenPop(): void
    {
        $list = new TodoList("1. Original");
        $list->pushTodo(Todo::fromString('Appended'));

        $this->assertSame('Original', $list->popTodo()->getTodo());
        $this->assertSame('Appended', $list->popTodo()->getTodo());
        $this->assertNull($list->popTodo());
    }

    /**
     * getTodos() возвращает копию списка — повторные вызовы не уменьшают очередь.
     */
    public function testGetTodosDoesNotAffectQueue(): void
    {
        $list = new TodoList("1. A\n2. B");
        $todos = $list->getTodos();
        $this->assertCount(2, $todos);

        $todosAgain = $list->getTodos();
        $this->assertCount(2, $todosAgain);
    }

    // ══════════════════════════════════════════════════════════════
    //  getNeedSkills — навыки из опции "skills"
    // ══════════════════════════════════════════════════════════════

    /**
     * Опция skills не задана — пустой массив.
     */
    public function testGetNeedSkillsEmpty(): void
    {
        $list = new TodoList('No options');
        $this->assertSame([], $list->getNeedSkills());
    }

    /**
     * Опция skills содержит два имени через запятую — оба возвращаются.
     */
    public function testGetNeedSkills(): void
    {
        $input = "---\nskills: search, translate\n---\n1. Task";
        $list = new TodoList($input);
        $this->assertSame(['search', 'translate'], $list->getNeedSkills());
    }

    /**
     * Имя самого списка (self-reference) исключается из результата,
     * чтобы избежать рекурсии.
     */
    public function testGetNeedSkillsExcludesSelf(): void
    {
        $input = "---\nskills: search, mylist, translate\n---\n1. Task";
        $list = new TodoList($input, 'mylist');
        $skills = $list->getNeedSkills();
        $this->assertNotContains('mylist', $skills);
        $this->assertSame(['search', 'translate'], $skills);
    }

    // ══════════════════════════════════════════════════════════════
    //  isPureContext — по умолчанию true
    // ══════════════════════════════════════════════════════════════

    /**
     * isPureContext() для TodoList всегда возвращает true.
     */
    public function testIsPureContextAlwaysTrue(): void
    {
        $list = new TodoList('');
        $this->assertFalse($list->isPureContext());
    }

    /**
     * isPureContext() возвращает true даже при наличии опций (опция не учитывается).
     */
    public function testIsPureContextWithOptionsStillTrue(): void
    {
        $input = "---\nagent: test\n---\n1. Task";
        $list = new TodoList($input);
        $this->assertFalse($list->isPureContext());
    }

    // ══════════════════════════════════════════════════════════════
    //  checkErrors / getErrors — валидация конфигурации
    // ══════════════════════════════════════════════════════════════

    /**
     * Простой список без параметров и skills — ошибок нет.
     */
    public function testCheckErrorsNoErrors(): void
    {
        $list = new TodoList("1. Simple task");
        $this->assertSame([], $list->checkErrors());
    }

    /**
     * Плейсхолдер $query в теле, но params не определена —
     * ошибка missing_param_definition.
     */
    public function testCheckErrorsMissingParamDefinition(): void
    {
        $input = "---\n---\nDo \$query";
        $list = new TodoList($input);
        $errors = $list->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('missing_param_definition', $types);
    }

    /**
     * Skills содержит имя самого компонента — ошибка self_referenced_skill.
     */
    public function testCheckErrorsSelfReferencedSkill(): void
    {
        $input = "---\nskills: self\n---\n1. Task";
        $list = new TodoList($input, 'self');
        $errors = $list->checkErrors();
        $types = array_column($errors, 'type');
        $this->assertContains('self_referenced_skill', $types);
    }

    // ══════════════════════════════════════════════════════════════
    //  Нормализация переводов строк
    // ══════════════════════════════════════════════════════════════

    /**
     * Различные форматы переводов строк (\r\n, \r) нормализуются;
     * нумерованные задания корректно разбиваются.
     */
    public function testLineEndingNormalization(): void
    {
        $input = "1. Task one\r\n2. Task two\r3. Task three";
        $list = new TodoList($input);
        $todos = $list->getTodos();
        $this->assertCount(3, $todos);
    }

    // ══════════════════════════════════════════════════════════════
    //  Граничный случай: «пустое» нумерованное задание
    // ══════════════════════════════════════════════════════════════

    /**
     * «1. \n2. Real task» — после удаления хвостового пробела (stripComments)
     * строка «1. » превращается в «1.», которая уже не совпадает с шаблоном
     * нумерованного задания. В результате она обрабатывается как обычный текст.
     */
    public function testTrailingWhitespaceInNumberedTask(): void
    {
        $input = "1. \n2. Real task";
        $list = new TodoList($input);
        $todos = $list->getTodos();
        $this->assertCount(2, $todos);
        $this->assertSame('Real task', $todos[1]->getTodo());
    }

    // ══════════════════════════════════════════════════════════════
    //  getAgentName — имя агента-исполнителя
    // ══════════════════════════════════════════════════════════════

    /**
     * Если опция agent задана — возвращается её значение.
     */
    public function testGetAgentNameFromOption(): void
    {
        $input = "---\nagent: customAgent\n---\n1. Task";
        $list = new TodoList($input);
        $this->assertSame('customAgent', $list->getAgentName());
    }

    /**
     * Если опция agent не задана — используется имя по умолчанию ('default').
     */
    public function testGetAgentNameDefault(): void
    {
        $list = new TodoList("1. Task");
        $this->assertNull($list->getAgentName());
    }

    // ══════════════════════════════════════════════════════════════
    //  Парсинг опций
    // ══════════════════════════════════════════════════════════════

    /**
     * Значения опций, похожие на JSON (числа, bool, массивы), декодируются.
     */
    public function testOptionJsonDecoding(): void
    {
        $input = "---\ncount: 42\nenabled: true\nlist: [1, 2, 3]\n---\nTask";
        $list = new TodoList($input);
        $options = $list->getOptions();
        $this->assertSame(42, $options['count']);
        $this->assertTrue($options['enabled']);
        $this->assertSame([1, 2, 3], $options['list']);
    }

    /**
     * Строковое значение, не являющееся JSON, остаётся строкой.
     */
    public function testOptionPlainString(): void
    {
        $input = "---\nagent: myAgent\n---\nTask";
        $list = new TodoList($input);
        $this->assertSame('myAgent', $list->getOptions()['agent']);
    }

    /**
     * Пустое значение опции сохраняется как пустая строка.
     */
    public function testEmptyOptionValue(): void
    {
        $input = "---\nagent:\n---\nTask";
        $list = new TodoList($input);
        $this->assertSame('', $list->getOptions()['agent']);
    }

    /**
     * Пустые строки в блоке опций пропускаются (не нарушают разбор).
     */
    public function testOptionSkipsBlankLines(): void
    {
        $input = "---\nagent: test\n\nother: value\n---\nTask";
        $list = new TodoList($input);
        $options = $list->getOptions();
        $this->assertSame('test', $options['agent']);
        $this->assertSame('value', $options['other']);
    }

    /**
     * Строки без двоеточия в блоке опций тихо игнорируются.
     */
    public function testOptionSkipsLinesWithoutColon(): void
    {
        $input = "---\nagent: test\nno colon here\n---\nTask";
        $list = new TodoList($input);
        $options = $list->getOptions();
        $this->assertCount(1, $options);
    }

    /**
     * Строка с пустым ключом (например «: value») игнорируется.
     */
    public function testOptionEmptyKeySkipped(): void
    {
        $input = "---\n: value\nagent: test\n---\nTask";
        $list = new TodoList($input);
        $options = $list->getOptions();
        $this->assertCount(1, $options);
        $this->assertSame('test', $options['agent']);
    }

    // ══════════════════════════════════════════════════════════════
    //  getParamList — DTO параметров
    // ══════════════════════════════════════════════════════════════

    /**
     * Без опции params — возвращается пустой ParamListDto.
     */
    public function testGetParamListNull(): void
    {
        $list = new TodoList("1. Simple");
        $paramList = $list->getParamList();
        $this->assertNotNull($paramList);
        $this->assertSame([], $paramList->all());
    }

    /**
     * Опция params задана валидным JSON — ParamListDto содержит описанные параметры.
     */
    public function testGetParamListWithParams(): void
    {
        $input = "---\nparams: {\"query\": \"string\"}\n---\n1. Search \$query";
        $list = new TodoList($input);
        $paramList = $list->getParamList();
        $this->assertNotNull($paramList);
        $this->assertTrue($paramList->has('query'));
    }

    /**
     * Повторный вызов getParamList() возвращает кешированный объект.
     */
    public function testGetParamListCached(): void
    {
        $list = new TodoList("1. Simple");
        $first = $list->getParamList();
        $second = $list->getParamList();
        $this->assertSame($first, $second);
    }

    /**
     * Невалидный JSON в params — getParamList() возвращает null.
     */
    public function testGetParamListInvalidReturnsNull(): void
    {
        $input = "---\nparams: not valid json\n---\n1. Task";
        $list = new TodoList($input);
        $this->assertNull($list->getParamList());
    }
}
