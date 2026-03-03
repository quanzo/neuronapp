<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\dto\params\ParamListDto;
use app\modules\neuron\helpers\PlaceholderHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see PlaceholderHelper}.
 *
 * PlaceholderHelper — статический хелпер, отвечающий за:
 *  - поиск плейсхолдеров вида $paramName (только латинские буквы) в тексте;
 *  - подстановку значений именованных параметров вместо плейсхолдеров;
 *  - валидацию соответствия описанных параметров (ParamListDto) и фактически
 *    используемых в тексте плейсхолдеров.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\PlaceholderHelper}
 */
class PlaceholderHelperTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════
    //  collectPlaceholders — поиск плейсхолдеров в тексте
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустая строка не содержит плейсхолдеров — метод должен вернуть пустой массив.
     */
    public function testCollectPlaceholdersEmptyString(): void
    {
        $this->assertSame([], PlaceholderHelper::collectPlaceholders(''));
    }

    /**
     * Текст без символа '$' не содержит плейсхолдеров.
     */
    public function testCollectPlaceholdersNoPlaceholders(): void
    {
        $this->assertSame([], PlaceholderHelper::collectPlaceholders('Hello world'));
    }

    /**
     * Единственный плейсхолдер $query извлекается корректно (без знака '$').
     */
    public function testCollectPlaceholdersSinglePlaceholder(): void
    {
        $this->assertSame(['query'], PlaceholderHelper::collectPlaceholders('Search for $query'));
    }

    /**
     * Несколько разных плейсхолдеров возвращаются в порядке первого вхождения.
     */
    public function testCollectPlaceholdersMultiplePlaceholders(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('$name is $age years old');
        $this->assertSame(['name', 'age'], $result);
    }

    /**
     * Повторяющиеся плейсхолдеры дедуплицируются — возвращается уникальный набор.
     */
    public function testCollectPlaceholdersDuplicatesAreUnique(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('$name and $name again');
        $this->assertSame(['name'], $result);
    }

    /**
     * Цифры в имени плейсхолдера не допускаются: $abc123 → захватывается только $abc.
     */
    public function testCollectPlaceholdersIgnoresDigitsInName(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('$abc123 test');
        $this->assertSame(['abc'], $result);
    }

    /**
     * Символ '$' без следующих за ним латинских букв (например, «$100») —
     * не является плейсхолдером и не попадает в результат.
     */
    public function testCollectPlaceholdersDollarSignAlone(): void
    {
        $this->assertSame([], PlaceholderHelper::collectPlaceholders('Price is $100'));
    }

    /**
     * Регистр имени учитывается: $Abc и $abc — разные плейсхолдеры.
     */
    public function testCollectPlaceholdersCaseSensitive(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('$Abc $abc');
        $this->assertSame(['Abc', 'abc'], $result);
    }

    /**
     * Плейсхолдеры, окружённые скобками и другими спецсимволами,
     * корректно извлекаются.
     */
    public function testCollectPlaceholdersAdjacentToSpecialChars(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('($query) and [$name]');
        $this->assertSame(['query', 'name'], $result);
    }

    /**
     * Плейсхолдеры в начале и в конце строки обнаруживаются без проблем.
     */
    public function testCollectPlaceholdersAtStartAndEnd(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('$start middle $end');
        $this->assertSame(['start', 'end'], $result);
    }

    /**
     * Подчёркивание не входит в допустимый набор символов имени плейсхолдера:
     * $my_param → захватывается только $my.
     */
    public function testCollectPlaceholdersWithUnderscores(): void
    {
        $result = PlaceholderHelper::collectPlaceholders('$my_param test');
        $this->assertSame(['my'], $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  renderWithParams — подстановка значений в текст
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустой текст возвращается как есть, даже при наличии параметров.
     */
    public function testRenderWithParamsEmptyText(): void
    {
        $this->assertSame('', PlaceholderHelper::renderWithParams('', ['key' => 'val']));
    }

    /**
     * Текст без плейсхолдеров возвращается неизменённым.
     */
    public function testRenderWithParamsNoPlaceholders(): void
    {
        $this->assertSame('Hello world', PlaceholderHelper::renderWithParams('Hello world', ['key' => 'val']));
    }

    /**
     * Простая подстановка: $name заменяется на значение параметра 'name'.
     */
    public function testRenderWithParamsSimpleSubstitution(): void
    {
        $result = PlaceholderHelper::renderWithParams('Hello $name', ['name' => 'World']);
        $this->assertSame('Hello World', $result);
    }

    /**
     * Множественная подстановка — каждый плейсхолдер заменяется соответствующим значением.
     */
    public function testRenderWithParamsMultipleSubstitutions(): void
    {
        $result = PlaceholderHelper::renderWithParams('$a + $b = $c', ['a' => '1', 'b' => '2', 'c' => '3']);
        $this->assertSame('1 + 2 = 3', $result);
    }

    /**
     * Если параметр отсутствует в переданном массиве, плейсхолдер заменяется
     * на пустую строку.
     */
    public function testRenderWithParamsMissingParamReplacedWithEmpty(): void
    {
        $result = PlaceholderHelper::renderWithParams('Hello $name!', []);
        $this->assertSame('Hello !', $result);
    }

    /**
     * Лишние параметры (не использованные в тексте) тихо игнорируются.
     */
    public function testRenderWithParamsExtraParamsIgnored(): void
    {
        $result = PlaceholderHelper::renderWithParams('Hello $name', ['name' => 'World', 'extra' => 'ignored']);
        $this->assertSame('Hello World', $result);
    }

    /**
     * Пустой массив параметров — все плейсхолдеры заменяются пустыми строками.
     */
    public function testRenderWithParamsEmptyParamsArray(): void
    {
        $result = PlaceholderHelper::renderWithParams('$a and $b', []);
        $this->assertSame(' and ', $result);
    }

    /**
     * Нестроковое значение параметра (int) автоматически приводится к строке.
     */
    public function testRenderWithParamsIntegerValueCastToString(): void
    {
        $result = PlaceholderHelper::renderWithParams('Count: $n', ['n' => 42]);
        $this->assertSame('Count: 42', $result);
    }

    /**
     * Один и тот же плейсхолдер, встречающийся дважды, заменяется оба раза.
     */
    public function testRenderWithParamsDuplicatePlaceholder(): void
    {
        $result = PlaceholderHelper::renderWithParams('$x and $x', ['x' => 'Y']);
        $this->assertSame('Y and Y', $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  validateParamList — проверка соответствия params и плейсхолдеров
    // ══════════════════════════════════════════════════════════════

    /**
     * Если все плейсхолдеры описаны в paramList и нет лишних описаний —
     * массив ошибок пуст.
     */
    public function testValidateParamListAllMatching(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(['query' => 'string']);
        $errors = PlaceholderHelper::validateParamList($list, ['query']);
        $this->assertSame([], $errors);
    }

    /**
     * Плейсхолдер $query используется в тексте, но не описан в params —
     * генерируется ошибка missing_param_definition.
     */
    public function testValidateParamListMissingDefinition(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(null);
        $errors = PlaceholderHelper::validateParamList($list, ['query']);
        $this->assertCount(1, $errors);
        $this->assertSame('missing_param_definition', $errors[0]['type']);
        $this->assertSame('query', $errors[0]['param']);
    }

    /**
     * Параметр 'query' описан в params, но не используется в тексте —
     * генерируется ошибка unused_param_definition.
     */
    public function testValidateParamListUnusedDefinition(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(['query' => 'string']);
        $errors = PlaceholderHelper::validateParamList($list, []);
        $this->assertCount(1, $errors);
        $this->assertSame('unused_param_definition', $errors[0]['type']);
        $this->assertSame('query', $errors[0]['param']);
    }

    /**
     * Если ParamListDto = null (ошибка парсинга), а плейсхолдеры есть —
     * каждый считается неописанным.
     */
    public function testValidateParamListNullParamList(): void
    {
        $errors = PlaceholderHelper::validateParamList(null, ['x']);
        $this->assertCount(1, $errors);
        $this->assertSame('missing_param_definition', $errors[0]['type']);
    }

    /**
     * Одновременно есть и неописанный плейсхолдер, и неиспользуемое описание —
     * две разных ошибки.
     */
    public function testValidateParamListBothMissingAndUnused(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(['unused' => 'string']);
        $errors = PlaceholderHelper::validateParamList($list, ['missing']);
        $this->assertCount(2, $errors);
        $types = array_column($errors, 'type');
        $this->assertContains('missing_param_definition', $types);
        $this->assertContains('unused_param_definition', $types);
    }

    /**
     * Пустой список params + пустой список плейсхолдеров — ошибок нет.
     */
    public function testValidateParamListEmptyBoth(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(null);
        $errors = PlaceholderHelper::validateParamList($list, []);
        $this->assertSame([], $errors);
    }
}
