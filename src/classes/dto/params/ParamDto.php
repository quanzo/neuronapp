<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\params;

/**
 * DTO одного параметра, описанного в опции "params".
 *
 * Инкапсулирует имя параметра, его тип, человекочитаемое описание
 * и флаг обязательности. Используется для построения схемы инструментов
 * (LLM‑tools) и для валидации плейсхолдеров в текстовых шаблонах.
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
     */
    public function __construct(
        private readonly string $name,
        private readonly string $type = 'string',
        private readonly ?string $description = null,
        private readonly bool $required = false,
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
}

