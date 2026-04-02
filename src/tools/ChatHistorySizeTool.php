<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\classes\dto\tools\ChatHistorySizeResultDto;
use app\modules\neuron\helpers\ChatHistoryEditHelper;

use function count;

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

        $count = $history ? count(ChatHistoryEditHelper::getMessages($history)) : 0;

        $dto = new ChatHistorySizeResultDto($count);

        return JsonHelper::encodeThrow($dto->toArray());
    }
}
