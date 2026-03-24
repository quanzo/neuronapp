<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\ToolEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования tool-событий.
 *
 * Преобразует события tool.started/tool.completed/tool.failed
 * в PSR-3 сообщения.
 */
final class ToolLoggingSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчики tool-событий.
     */
    public static function register(LoggerInterface $logger): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(
            EventNameEnum::TOOL_STARTED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof ToolEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->info('Tool event: started', $payload->toArray());
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::TOOL_COMPLETED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof ToolEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->info('Tool event: completed', $payload->toArray());
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::TOOL_FAILED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof ToolEventDto) {
                    return;
                }

                $effectiveLogger = self::resolveLogger($payload, $logger);
                $effectiveLogger->error('Tool event: failed', $payload->toArray());
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
    private static function resolveLogger(ToolEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
