<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\ChatHistoryMessageMetaDto;
use app\modules\neuron\helpers\ChatHistoryToolMessageHelper;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function count;
use function json_encode;
use function mb_strlen;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент получения метаданных сообщения из истории без текста.
 *
 * Возвращает роль, длину content в символах, индекс и (если это tool-call/result)
 * сигнатуру инструмента.
 */
final class ChatHistoryMetaTool extends ATool
{
    public function __construct(
        string $name = 'chat_history.meta',
        string $description = 'Метаданные сообщения истории по индексу: роль, длина в символах, tool-сигнатура (без текста).',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name       : 'index',
                type       : PropertyType::INTEGER,
                description: 'Индекс сообщения в истории (0-based).',
                required   : true,
            ),
        ];
    }

    /**
     * Возвращает метаданные сообщения.
     *
     * @param int $index Индекс сообщения (0-based).
     *
     * @return string JSON
     */
    public function __invoke(int $index): string
    {
        $agentCfg = $this->getAgentCfg();
        $history = $agentCfg?->getChatHistory();

        /** @var Message[] $messages */
        $messages = [];
        if ($history instanceof AbstractFullChatHistory) {
            $messages = $history->getFullMessages();
        } elseif ($history !== null) {
            $messages = $history->getMessages();
        }

        $count = count($messages);
        if ($index < 0 || $index >= $count) {
            return json_encode([
                'error'    => 'Index out of range.',
                'index'    => $index,
                'count'    => $count,
                'minIndex' => 0,
                'maxIndex' => $count > 0 ? $count - 1 : -1,
            ], JSON_UNESCAPED_UNICODE);
        }

        $msg     = $messages[$index];
        $content = (string) ($msg->getContent() ?? '');
        $chars   = mb_strlen($content);

        $isTool = $msg instanceof ToolCallMessage || $msg instanceof ToolResultMessage;
        $sig    = $isTool ? ChatHistoryToolMessageHelper::extractToolSignature($msg) : null;

        $dto = new ChatHistoryMessageMetaDto(
            index        : $index,
            role         : (string) $msg->getRole(),
            chars        : $chars,
            isTool       : $isTool,
            toolSignature: $sig,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
