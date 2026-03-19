<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\ChatHistorySizeResultDto;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;

use function count;
use function json_encode;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент получения размера полной истории чата.
 *
 * Возвращает количество сообщений в истории (0-based индексация).
 * Нужен LLM, чтобы безопасно работать с индексами перед вызовами meta/message.
 */
final class ChatHistorySizeTool extends ATool
{
    public function __construct(
        string $name = 'chat_history.size',
        string $description = 'Размер полной истории чата: количество сообщений (0-based).',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * Возвращает размер истории.
     *
     * @return string JSON: {"count": int}
     */
    public function __invoke(): string
    {
        $agentCfg = $this->getAgentCfg();
        $history = $agentCfg?->getChatHistory();

        if ($history instanceof AbstractFullChatHistory) {
            $count = count($history->getFullMessages());
        } else {
            $count = $history ? count($history->getMessages()) : 0;
        }

        $dto = new ChatHistorySizeResultDto($count);

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
