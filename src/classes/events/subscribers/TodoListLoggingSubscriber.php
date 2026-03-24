<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования todo-событий.
 */
final class TodoListLoggingSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчики todo-событий.
     */
    public static function register(LoggerInterface $logger): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(EventNameEnum::TODO_STARTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info('Todo event: started', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::TODO_COMPLETED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info('Todo event: completed', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::TODO_FAILED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->error('Todo event: failed', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::TODO_GOTO_REQUESTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info('Todo event: goto_requested', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::TODO_GOTO_REJECTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->warning('Todo event: goto_rejected', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::TODO_AGENT_SWITCHED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info('Todo event: agent_switched', $payload->toArray());
        }, '*');

        self::$isRegistered = true;
    }

    /**
     * Сбрасывает флаг регистрации (для тестов).
     */
    public static function reset(): void
    {
        self::$isRegistered = false;
    }

    private static function resolveLogger(TodoEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
