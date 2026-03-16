<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\params\ParamDto;
use app\modules\neuron\classes\dto\params\ParamListDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ParamListDto}.
 *
 * ParamListDto — DTO-обёртка над набором параметров (ParamDto),
 * получаемых из опции "params" текстового компонента.
 * Ключевой метод — tryFromOptionValue(): он принимает произвольное значение
 * (null, строку-JSON, массив) и возвращает пару [ParamListDto|null, errors[]].
 *
 * Тесты проверяют:
 *  - разбор null, пустых строк, валидного и невалидного JSON;
 *  - валидацию имён параметров (только латинские буквы);
 *  - различные форматы описания параметра (строка-тип, объект с type/description/required);
 *  - корректность ошибок при невалидных данных;
 *  - работу методов has(), get(), all().
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\dto\params\ParamListDto}
 */
class ParamListDtoTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════
    //  tryFromOptionValue: null — отсутствие опции params
    // ══════════════════════════════════════════════════════════════

    /**
     * null на входе означает отсутствие опции params —
     * возвращается пустой ParamListDto без ошибок.
     */
    public function testNullInputReturnsEmptyList(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(null);
        $this->assertInstanceOf(ParamListDto::class, $list);
        $this->assertSame([], $list->all());
        $this->assertSame([], $errors);
    }

    // ══════════════════════════════════════════════════════════════
    //  tryFromOptionValue: строковые входы
    // ══════════════════════════════════════════════════════════════

    /**
     * Пустая строка трактуется как отсутствие параметров.
     */
    public function testEmptyStringReturnsEmptyList(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue('');
        $this->assertInstanceOf(ParamListDto::class, $list);
        $this->assertSame([], $list->all());
        $this->assertSame([], $errors);
    }

    /**
     * Строка из пробелов также трактуется как пустая.
     */
    public function testWhitespaceOnlyStringReturnsEmptyList(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue('   ');
        $this->assertInstanceOf(ParamListDto::class, $list);
        $this->assertSame([], $list->all());
        $this->assertSame([], $errors);
    }

    /**
     * Валидная JSON-строка с описанием одного параметра.
     */
    public function testValidJsonStringInput(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue('{"query": "string"}');
        $this->assertInstanceOf(ParamListDto::class, $list);
        $this->assertSame([], $errors);
        $this->assertTrue($list->has('query'));
        $this->assertSame('string', $list->get('query')->getType());
    }

    /**
     * Невалидный JSON — возвращается null + ошибка invalid_params_json.
     */
    public function testInvalidJsonStringReturnsError(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue('{invalid json}');
        $this->assertNull($list);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_params_json', $errors[0]['type']);
    }

    // ══════════════════════════════════════════════════════════════
    //  tryFromOptionValue: нестроковые и немассивные типы
    // ══════════════════════════════════════════════════════════════

    /**
     * Целое число не является допустимым значением params — ошибка invalid_params_type.
     */
    public function testIntegerInputReturnsError(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(42);
        $this->assertNull($list);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_params_type', $errors[0]['type']);
    }

    /**
     * bool не является допустимым значением params — ошибка invalid_params_type.
     */
    public function testBoolInputReturnsError(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(true);
        $this->assertNull($list);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_params_type', $errors[0]['type']);
    }

    // ══════════════════════════════════════════════════════════════
    //  tryFromOptionValue: валидация имён параметров
    // ══════════════════════════════════════════════════════════════

    /**
     * Имя с цифрами (abc123) не соответствует шаблону [a-zA-Z]+ — ошибка.
     */
    public function testInvalidParamNameWithDigits(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(['abc123' => 'string']);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_name', $errors[0]['type']);
    }

    /**
     * Подчёркивание в имени (my_param) не допускается — ошибка.
     */
    public function testInvalidParamNameWithUnderscore(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(['my_param' => 'string']);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_name', $errors[0]['type']);
    }

    /**
     * Числовой ключ массива (0) не является допустимым именем параметра.
     */
    public function testNumericKeyAsParamName(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([0 => 'string']);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_name', $errors[0]['type']);
    }

    /**
     * Пустая строка в качестве ключа — не допустима.
     */
    public function testEmptyStringKey(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(['' => 'string']);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_name', $errors[0]['type']);
    }

    // ══════════════════════════════════════════════════════════════
    //  tryFromOptionValue: форматы описания параметра
    // ══════════════════════════════════════════════════════════════

    /**
     * Строковое описание параметра ("integer") задаёт только тип.
     * description = null, required = false.
     */
    public function testStringDefinitionSetsType(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(['query' => 'integer']);
        $this->assertSame([], $errors);
        $param = $list->get('query');
        $this->assertSame('integer', $param->getType());
        $this->assertNull($param->getDescription());
        $this->assertFalse($param->isRequired());
    }

    /**
     * Объектное описание со всеми полями: type, description, required.
     */
    public function testObjectDefinitionFull(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'query' => [
                'type' => 'string',
                'description' => 'Search query',
                'required' => true,
                'default' => 'php',
            ],
        ]);
        $this->assertSame([], $errors);
        $param = $list->get('query');
        $this->assertSame('string', $param->getType());
        $this->assertSame('Search query', $param->getDescription());
        $this->assertTrue($param->isRequired());
        $this->assertSame('php', $param->getDefault());
    }

    /**
     * Объектное описание без поля type — тип по умолчанию 'string'.
     */
    public function testObjectDefinitionWithDefaultType(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'query' => ['description' => 'Search query'],
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('string', $list->get('query')->getType());
    }

    /**
     * Поле default не влияет на наличие ошибок и сохраняется в ParamDto.
     */
    public function testDefaultFieldDoesNotAffectErrors(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'ok' => [
                'type' => 'string',
                'default' => 'value',
            ],
            'bad' => [
                'type' => '',
                'default' => 'ignored',
            ],
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_type_value', $errors[0]['type']);
        $this->assertSame('value', $list->get('ok')->getDefault());
    }

    /**
     * Нескалярное описание (int) вместо строки или массива —
     * ошибка invalid_param_definition_type.
     */
    public function testInvalidDefinitionType(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue(['query' => 42]);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_definition_type', $errors[0]['type']);
        $this->assertSame('query', $errors[0]['param']);
    }

    /**
     * Пустая строка в поле type — ошибка invalid_param_type_value.
     */
    public function testInvalidTypeValue(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'query' => ['type' => ''],
        ]);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_type_value', $errors[0]['type']);
    }

    /**
     * Нестроковое значение в поле type (int) — ошибка invalid_param_type_value.
     */
    public function testInvalidTypeValueNonString(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'query' => ['type' => 123],
        ]);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_type_value', $errors[0]['type']);
    }

    /**
     * Нестроковое значение description (int) — ошибка invalid_param_description_value,
     * description сбрасывается в null.
     */
    public function testInvalidDescriptionValue(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'query' => ['description' => 123],
        ]);
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_param_description_value', $errors[0]['type']);
    }

    /**
     * Поле required не задано — по умолчанию false.
     */
    public function testRequiredDefaultFalse(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'query' => ['type' => 'string'],
        ]);
        $this->assertSame([], $errors);
        $this->assertFalse($list->get('query')->isRequired());
    }

    // ══════════════════════════════════════════════════════════════
    //  has / get / all — доступ к данным списка
    // ══════════════════════════════════════════════════════════════

    /**
     * has() возвращает true для существующего параметра.
     */
    public function testHasReturnsTrueForExisting(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(['query' => 'string']);
        $this->assertTrue($list->has('query'));
    }

    /**
     * has() возвращает false для несуществующего параметра.
     */
    public function testHasReturnsFalseForMissing(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(['query' => 'string']);
        $this->assertFalse($list->has('missing'));
    }

    /**
     * get() возвращает null для отсутствующего параметра.
     */
    public function testGetReturnsNullForMissing(): void
    {
        [$list] = ParamListDto::tryFromOptionValue(['query' => 'string']);
        $this->assertNull($list->get('missing'));
    }

    /**
     * all() возвращает плоский список всех ParamDto.
     */
    public function testAllReturnsAllParams(): void
    {
        [$list] = ParamListDto::tryFromOptionValue([
            'query' => 'string',
            'limit' => 'integer',
        ]);
        $all = $list->all();
        $this->assertCount(2, $all);
        $this->assertContainsOnlyInstancesOf(ParamDto::class, $all);
    }

    // ══════════════════════════════════════════════════════════════
    //  Множественные ошибки
    // ══════════════════════════════════════════════════════════════

    /**
     * При нескольких невалидных именах ошибки собираются в массив,
     * а валидные параметры всё равно попадают в список.
     */
    public function testMultipleErrorsCollected(): void
    {
        [$list, $errors] = ParamListDto::tryFromOptionValue([
            'valid' => 'string',
            '123' => 'string',
            'also_bad' => 'string',
        ]);
        $this->assertCount(2, $errors);
        $this->assertTrue($list->has('valid'));
    }
}
