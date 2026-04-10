<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\LlmInferenceEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования события подготовки LLM-инференса.
 *
 * Подписывается на `llm.inference.prepared`, payload — {@see LlmInferenceEventDto}.
 * Пишет лог уровня `info`.
 *
 * Пример использования:
 * ```php
 * LlmInferenceLoggingSubscriber::register($logger);
 * ```
 */
final class LlmInferenceLoggingSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчик события подготовки LLM-инференса.
     */
    public static function register(LoggerInterface $logger): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(
            EventNameEnum::LLM_INFERENCE_PREPARED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof LlmInferenceEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->info('LLM event: inference_prepared | ' . (string) $payload, $payload->toArray());
            },
            '*'
        );

        self::$isRegistered = true;
    }

    /**
     * Сбрасывает флаг регистрации (для тестов).
     */
    public static function reset(): void
    {
        self::$isRegistered = false;
    }

    /**
     * Возвращает логгер из конфигурации агента события или fallback-логгер.
     */
    private static function resolveLogger(LlmInferenceEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
