<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\todo;

use app\modules\neuron\classes\dto\cmd\AgentCmdDto;
use app\modules\neuron\interfaces\ITodo;
use app\modules\neuron\helpers\PlaceholderHelper;
use app\modules\neuron\classes\dto\cmd\CmdDto;
use app\modules\neuron\helpers\FileContextHelper;

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
     * D тексте задан кастомный агент-исполнитель в конструкции @@agent("agent-custom")
     *
     * @var AgentCmdDto|null|false
     */
    private AgentCmdDto|null|false $agentDto = null;

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
        if ($params === null) {
            return $this->text;
        }

        return PlaceholderHelper::renderWithParams($this->text, $params);
    }

    /**
     * Возвращает список управляющих команд, определённых в тексте задания.
     *
     * Команды ищутся по синтаксису "@@name(...)" в исходном тексте todo
     * без учёта параметров (плейсхолдеры не подставляются).
     *
     * @return list<CmdDto> Массив DTO-команд в порядке появления в тексте.
     */
    public function getCmdList(): array
    {
        return FileContextHelper::extractCmdFromBody($this->text);
    }

    /**
     * Из текста задания вернуть агента, который указан в `@@agent("agent-name")`
     *
     * @return AgentCmdDto|null
     */
    public function getSwitchToAgent(): ?AgentCmdDto {
        if ($this->agentDto === false) {
            return null;
        }
        if (!$this->agentDto) {
            $body = $this->text;
            $cmds = FileContextHelper::extractCmdFromBody($body);
            $agentDto = null;
            foreach ($cmds as $cmd) {
                if ($cmd instanceof AgentCmdDto) {
                    $agentDto = $cmd;
                    // убираем @@agent из текста
                    $body = $cmd->replaceSignatureInText($body, '');
                }
            }
            if ($agentDto) {
                $this->text = $body;
                $this->agentDto = $agentDto;
            } else {
                $this->agentDto = false;
            }
        }
        return $this->agentDto ? $this->agentDto : null;
    }
}
