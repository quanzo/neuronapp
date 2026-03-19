<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\ChatHistoryMessageDto;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\helpers\ChatHistoryToolMessageHelper;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function count;
use function json_encode;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент получения сообщения истории по индексу.
 *
 * Возвращает роль, индекс, content и (если это tool-call/result) сигнатуру инструмента.
 */
final class ChatHistoryMessageTool extends ATool
{
    public function __construct(
        string $name = 'chat_history.message',
        string $description = 'Сообщение истории по индексу: роль + текст + tool-сигнатура (0-based).',
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
     * Возвращает сообщение по индексу.
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

        $msg = $messages[$index];
        $content = (string) ($msg->getContent() ?? '');

        $isTool = $msg instanceof ToolCallMessage || $msg instanceof ToolResultMessage;
        $sig = $isTool ? ChatHistoryToolMessageHelper::extractToolSignature($msg) : null;

        $dto = new ChatHistoryMessageDto(
            index        : $index,
            role         : (string) $msg->getRole(),
            content      : $content,
            toolSignature: $sig,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
