<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\params;

/**
 * DTO одного параметра, описанного в опции "params".
 *
 * Инкапсулирует имя параметра, его тип, человекочитаемое описание,
 * флаг обязательности и значение по умолчанию. Используется для
 * построения схемы инструментов (LLM‑tools), валидации плейсхолдеров
 * и формирования итогового набора параметров для подстановки.
 */
final class ParamDto
{
    /**
     * Создаёт DTO‑объект параметра.
     *
     * @param string      $name        Имя параметра, совпадающее с именем плейсхолдера без знака '$'
     *                                 (например, "query" для плейсхолдера "$query").
     * @param string      $type        Логический тип параметра (например, "string", "integer", "boolean"),
     *                                 используется при формировании описания инструмента.
     * @param string|null $description Краткое человекочитаемое описание значения параметра или null,
     *                                 если дополнительное описание не требуется.
     * @param bool        $required    Признак обязательности параметра: true — значение обязательно,
     *                                 false — параметр является необязательным.
     * @param mixed       $default     Значение параметра по умолчанию, используемое при формировании
     *                                 итогового набора параметров, если явное значение не передано.
     */
    public function __construct(
        private readonly string $name,
        private readonly string $type = 'string',
        private readonly ?string $description = null,
        private readonly bool $required = false,
        private readonly mixed $default = null,
    ) {
    }

    /**
     * Возвращает имя параметра.
     *
     * @return string Имя параметра, совпадающее с именем плейсхолдера без '$'.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Возвращает логический тип параметра.
     *
     * @return string Строковое обозначение типа (например, "string", "integer").
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Возвращает человекочитаемое описание параметра.
     *
     * @return string|null Текстовое описание назначения параметра или null,
     *                     если описание не задано.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Показывает, является ли параметр обязательным.
     *
     * @return bool true, если параметр обязательный, иначе false.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Возвращает значение параметра по умолчанию.
     *
     * @return mixed Значение по умолчанию или null, если default не задан.
     */
    public function getDefault(): mixed
    {
        return $this->default;
    }
}
