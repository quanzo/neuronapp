<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\params\ParamDto;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ParamDto}.
 *
 * ParamDto — неизменяемый DTO одного параметра, описанного в опции "params".
 * Хранит имя параметра, его логический тип (string, integer и т.д.),
 * текстовое описание и флаг обязательности. Используется при построении
 * инструментов для LLM и при валидации плейсхолдеров.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\dto\params\ParamDto}
 */
class ParamDtoTest extends TestCase
{
    /**
     * Конструктор с единственным обязательным аргументом (имя) —
     * остальные параметры принимают значения по умолчанию:
     * type = 'string', description = null, required = false.
     */
    public function testDefaultValues(): void
    {
        $dto = new ParamDto('query');
        $this->assertSame('query', $dto->getName());
        $this->assertSame('string', $dto->getType());
        $this->assertNull($dto->getDescription());
        $this->assertFalse($dto->isRequired());
    }

    /**
     * Все четыре параметра конструктора заданы явно — все геттеры
     * возвращают соответствующие значения.
     */
    public function testAllParametersSpecified(): void
    {
        $dto = new ParamDto('search', 'string', 'Search query text', true);
        $this->assertSame('search', $dto->getName());
        $this->assertSame('string', $dto->getType());
        $this->assertSame('Search query text', $dto->getDescription());
        $this->assertTrue($dto->isRequired());
    }

    /**
     * Тип 'integer' сохраняется и возвращается корректно.
     */
    public function testIntegerType(): void
    {
        $dto = new ParamDto('count', 'integer', null, false);
        $this->assertSame('integer', $dto->getType());
    }

    /**
     * Тип 'boolean' в сочетании с required = true.
     */
    public function testBooleanType(): void
    {
        $dto = new ParamDto('flag', 'boolean', 'Toggle', true);
        $this->assertSame('boolean', $dto->getType());
        $this->assertTrue($dto->isRequired());
    }

    /**
     * Пустая строка в описании — отличается от null (описание задано, но пусто).
     */
    public function testEmptyDescription(): void
    {
        $dto = new ParamDto('x', 'string', '', false);
        $this->assertSame('', $dto->getDescription());
    }
}
