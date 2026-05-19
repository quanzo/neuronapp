<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\cmd;

/**
 * DTO команды "thinking", представляемой строкой вида "@@thinking" или "@@thinking(true|false)".
 *
 * Команда переключает режим размышлений (think) только на время исполнения
 * соответствующего элемента todo. Валидация аргументов мягкая:
 * {@see ThinkingCmdDto::resolveEnabled()} возвращает null при некорректном аргументе.
 *
 * Пример:
 * ```php
 * $dto = CmdDto::fromString('@@thinking(true)');
 * $enabled = $dto instanceof ThinkingCmdDto ? $dto->resolveEnabled() : null;
 * ```
 */
class ThinkingCmdDto extends CmdDto
{
    /**
     * Возвращает желаемое состояние think для выполнения todo.
     *
     * Поддерживаемые варианты:
     * - без аргументов: включить think (true);
     * - один аргумент: true/false, 1/0, "true"/"false";
     * - два и более аргументов: используется первый аргумент.
     *
     * @return bool|null true — включить, false — выключить, null — наследовать сессию.
     */
    public function resolveEnabled(): ?bool
    {
        $params = $this->getParams();

        if ($params === []) {
            return true;
        }

        if (count($params) >= 1) {
            return $this->parseBoolish($params[0]);
        }

        return null;
    }

    /**
     * Приводит скаляр из разбора команды к bool или null.
     *
     * @param mixed $value Скаляр из разбора команды.
     *
     * @return bool|null
     */
    protected function parseBoolish(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === 'true' || $normalized === '1') {
            return true;
        }
        if ($normalized === 'false' || $normalized === '0') {
            return false;
        }

        return null;
    }

    /**
     * Создаёт DTO команды "thinking" из разобранных частей.
     *
     * @param string $name   Имя команды.
     * @param array  $params Позиционные параметры команды.
     *
     * @return self Экземпляр {@see ThinkingCmdDto}.
     */
    protected static function fromParts(string $name, array $params): self
    {
        return new self($name, $params);
    }
}
