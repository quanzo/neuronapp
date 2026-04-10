<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tui;

/**
 * DTO контекста post-hook: исходный ввод + фактический вывод.
 *
 * Пример использования:
 *
 * ```php
 * $ctx = new PostOutputContextDto($input, $output);
 * ```
 */
final class PostOutputContextDto
{
    /**
     * @param string $originalInput  Исходный введённый текст.
     * @param string $renderedOutput Текст, который был добавлен в history для вывода.
     */
    public function __construct(
        private readonly string $originalInput,
        private readonly string $renderedOutput,
    ) {
    }

    /**
     * Возвращает исходный введённый текст.
     */
    public function getOriginalInput(): string
    {
        return $this->originalInput;
    }

    /**
     * Возвращает текст, который выводился в кадре.
     */
    public function getRenderedOutput(): string
    {
        return $this->renderedOutput;
    }
}
