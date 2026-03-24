<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\RunEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования run-событий.
 *
 * Преобразует события run.started/run.finished/run.failed
 * в PSR-3 сообщения, чтобы убрать дублирование в доменном коде.
 *
 * Пример использования:
 * ```php
 * RunLoggingSubscriber::register($logger);
 * ```
 */
final class RunLoggingSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчики run-событий.
     */
    public static function register(LoggerInterface $logger): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(
            EventNameEnum::RUN_STARTED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof RunEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->info('Run event: started', $payload->toArray());
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::RUN_FINISHED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof RunEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->info('Run event: finished', $payload->toArray());
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::RUN_FAILED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof RunEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->error('Run event: failed', $payload->toArray());
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
    private static function resolveLogger(RunEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
