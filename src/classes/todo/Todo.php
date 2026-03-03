<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\todo;

use app\modules\neuron\interfaces\ITodo;

/**
 * Класс одного задания Todo.
 *
 * Хранит многострочный текст задачи и предоставляет
 * статический конструктор для создания экземпляров из строки.
 */
class Todo implements ITodo
{
    /**
     * Полный текст задания.
     */
    private string $text;

    /**
     * Создает экземпляр задания с указанным текстом.
     *
     * Используйте {@see Todo::fromString()} для внешнего кода.
     *
     * @param string $text Нормализованный текст задания.
     */
    private function __construct(string $text)
    {
        $this->text = $text;
    }

    /**
     * Статический конструктор задания из произвольной строки.
     *
     * Нормализует переводы строк к формату "\n" и возвращает новый объект.
     *
     * @param string $text Входной текст задания.
     */
    public static function fromString(string $text): self
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        return new self($normalized);
    }

    /**
     * Возвращает сохраненный текст задания.
     */
    public function getTodo(?array $params = null): string
    {
        if ($params === null || $this->text === '') {
            return $this->text;
        }

        $matches = [];
        preg_match_all('/\$([a-zA-Z]+)/', $this->text, $matches);

        if (empty($matches[1])) {
            return $this->text;
        }

        $replacements = [];

        foreach (array_unique($matches[1]) as $placeholder) {
            $value = array_key_exists($placeholder, $params) ? (string) $params[$placeholder] : '';
            $replacements['$' . $placeholder] = $value;
        }

        return strtr($this->text, $replacements);
    }
}

