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
        ];

        if ($this->mode === 'debug') {
            $context['messages_count'] = count($messages);
            $context['messages']       = LlmPayloadLogSanitizer::sanitize($messages, 1000, 5);
        }

        $this->logger->info('llm.inference.prepared', $context);

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
}
