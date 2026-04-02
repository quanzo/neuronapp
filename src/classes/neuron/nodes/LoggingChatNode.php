<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\nodes;

use app\modules\neuron\helpers\LlmPayloadLogSanitizer;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use Psr\Log\LoggerInterface;

use function array_map;
use function count;
use function preg_replace;
use function trim;

/**
 * Узел выполнения инференса с логированием входных данных в LLM.
 *
 * Логирует системный промпт и список инструментов до передачи запроса провайдеру.
 */
final class LoggingChatNode extends \NeuronAI\Agent\Nodes\ChatNode
{
    /**
     * @param AIProviderInterface $provider Провайдер LLM.
     * @param LoggerInterface     $logger Логгер приложения.
     * @param string              $mode Режим детализации логов: summary|debug.
     */
    public function __construct(
        AIProviderInterface $provider,
        private readonly LoggerInterface $logger,
        private readonly string $mode = 'summary',
    ) {
        parent::__construct($provider);
    }

    /**
     * Логирует подготовленный контекст инференса и делегирует запрос провайдеру.
     *
     * @param AIInferenceEvent $event Событие инференса.
     * @param Message[]        $messages История сообщений.
     *
     * @return Message
     */
    protected function inference(AIInferenceEvent $event, array $messages): Message
    {
        $tools = $event->tools;

        $preview = LlmPayloadLogSanitizer::preview($event->instructions);
        $lastUserMessage = $this->getLastUserMessageText($messages);
        $userMessagePreview = $this->buildOneLinePreview($lastUserMessage, 300);
        $context = [
            'event'       => 'llm.inference.prepared',
            'tools_count' => count($tools),
            'tools_names' => array_map(
                static fn (ToolInterface $tool): string => $tool->getName(),
                $tools
            ),
            'tool_required_params' => $this->toolRequiredParams($tools),
            'instructions_preview' => $preview['preview'],
            'instructions_length'  => $preview['length'],
            'llm_user_message_preview' => $userMessagePreview['preview'],
            'llm_user_message_length' => $userMessagePreview['length'],
        ];

        if ($this->mode === 'debug') {
            $context['messages_count'] = count($messages);
            $context['messages']       = LlmPayloadLogSanitizer::sanitize($messages, 1000, 5);
        }

        $logMessage = 'llm.inference.prepared';
        if ($userMessagePreview['preview'] !== '') {
            $logMessage .= ': ' . $userMessagePreview['preview'];
        }

        $this->logger->info($logMessage, $context);

        return parent::inference($event, $messages);
    }

    /**
     * Собирает required-параметры по инструментам.
     *
     * @param ToolInterface[] $tools Инструменты инференса.
     *
     * @return array<string, array<int, string>>
     */
    private function toolRequiredParams(array $tools): array
    {
        $result = [];
        foreach ($tools as $tool) {
            $result[$tool->getName()] = $tool->getRequiredProperties();
        }

        return $result;
    }

    /**
     * Возвращает текст последнего сообщения роли user (если есть).
     *
     * @param Message[] $messages
     */
    private function getLastUserMessageText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i] ?? null;
            if (!$msg instanceof Message) {
                continue;
            }

            if ($msg->getRole() !== 'user') {
                continue;
            }

            return (string) ($msg->getContent() ?? '');
        }

        return '';
    }

    /**
     * Готовит однострочное превью текста для сообщения лога.
     *
     * @return array{preview: string, length: int}
     */
    private function buildOneLinePreview(?string $text, int $maxLength): array
    {
        $text = (string) $text;
        $textOneLine = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $textOneLine = trim($textOneLine);

        return LlmPayloadLogSanitizer::preview($textOneLine, $maxLength);
    }
}
