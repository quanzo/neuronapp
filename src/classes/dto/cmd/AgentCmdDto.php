<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\cmd;

/**
 * DTO команды "agent", представляемой строкой вида "@@agent(\"agent-name\")".
 *
 * Команда используется как управляющая директива для переключения агента
 * выполнения в рамках одного элемента todo.
 *
 * Валидация аргументов выполняется мягко: {@see AgentCmdDto::getAgentName()}
 * возвращает null, если аргументы некорректны (не 1 параметр, не строка, пусто).
 */
final class AgentCmdDto extends CmdDto
{
    /**
     * Возвращает имя агента из команды.
     *
     * Ожидается ровно один параметр строкового типа, непустой после trim().
     *
     * @return string|null Имя агента или null при некорректной команде.
     */
    public function getAgentName(): ?string
    {
        $params = $this->getParams();
        $rawName = $params[0] ?? null;

        if (count($params) !== 1 || !is_string($rawName)) {
            return null;
        }

        $name = trim($rawName);
        if ($name === '') {
            return null;
        }

        return $name;
    }

    /**
     * Создаёт DTO команды "agent" из разобранных частей.
     *
     * @param string $name   Имя команды.
     * @param array  $params Позиционные параметры команды.
     *
     * @return self Экземпляр {@see AgentCmdDto}.
     */
    protected static function fromParts(string $name, array $params): self
    {
        return new self($name, $params);
    }
}
