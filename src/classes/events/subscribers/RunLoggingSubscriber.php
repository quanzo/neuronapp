<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\BaseEventDto;
use app\modules\neuron\classes\dto\events\RunEventDto;
use app\modules\neuron\classes\dto\events\RunErrorEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования run-событий.
 *
 * Преобразует события run.started / run.finished / run.failed
 * в PSR-3 сообщения. Для сообщения используется строковое представление
 * DTO ({@see BaseEventDto::__toString()}), контекст — массив {@see BaseEventDto::toArray()}.
 *
 * - `run.started` и `run.finished` ожидают payload {@see RunEventDto};
 * - `run.failed` ожидает payload {@see RunErrorEventDto}.
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
                $effectiveLogger->info('Run event: started | ' . (string) $payload, $payload->toArray());
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
                $effectiveLogger->info('Run event: finished | ' . (string) $payload, $payload->toArray());
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
                $effectiveLogger->error('Run event: failed | ' . (string) $payload, $payload->toArray());
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
