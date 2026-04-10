<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\nodes;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\dto\events\LlmInferenceEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\helpers\LlmPayloadLogSanitizer;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function count;
use function preg_replace;
use function trim;

/**
 * Узел выполнения инференса с логированием входных данных в LLM.
 *
 * Публикует событие `llm.inference.prepared` через {@see EventBus}
 * с полным контекстом инференса (system prompt, инструменты, user-сообщение).
 * Логирование выполняется подписчиком {@see \app\modules\neuron\classes\events\subscribers\LlmInferenceLoggingSubscriber}.
 */
final class LoggingChatNode extends \NeuronAI\Agent\Nodes\ChatNode
{
    /**
     * @param AIProviderInterface      $provider Провайдер LLM.
     * @param ?ConfigurationAgent      $agentCfg Конфигурация агента (для DTO и resolve логгера).
     * @param string                   $mode Режим детализации логов: summary|debug.
     */
    public function __construct(
        AIProviderInterface $provider,
        private readonly ?ConfigurationAgent $agentCfg = null,
        private readonly string $mode = 'summary',
    ) {
        parent::__construct($provider);
    }

    /**
     * Публикует событие подготовки инференса и делегирует запрос провайдеру.
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

        $dto = (new LlmInferenceEventDto())
            ->setAgent($this->agentCfg)
            ->setSessionKey($this->agentCfg?->getSessionKey() ?? '')
            ->setRunId('')
            ->setTimestamp(date(\DateTimeInterface::ATOM))
            ->setToolsCount(count($tools))
            ->setToolsNames(array_map(
                static fn (ToolInterface $tool): string => $tool->getName(),
                $tools
            ))
            ->setToolRequiredParams($this->toolRequiredParams($tools))
            ->setInstructionsPreview($preview['preview'])
            ->setInstructionsLength($preview['length'])
            ->setUserMessagePreview($userMessagePreview['preview'])
            ->setUserMessageLength($userMessagePreview['length']);

        if ($this->mode === 'debug') {
            $dto->setMessagesCount(count($messages))
                ->setMessagesSanitized(LlmPayloadLogSanitizer::sanitize($messages, 1000, 5));
        }

        EventBus::trigger(EventNameEnum::LLM_INFERENCE_PREPARED->value, '*', $dto);

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
