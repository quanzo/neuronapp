<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\dto\events\TodoErrorEventDto;
use app\modules\neuron\classes\dto\events\TodoGotoRejectedEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования todo-событий.
 *
 * - `todo.started`, `todo.completed`, `todo.goto_requested`, `todo.agent_switched`
 *    ожидают payload {@see TodoEventDto};
 * - `todo.failed` ожидает payload {@see TodoErrorEventDto};
 * - `todo.goto_rejected` ожидает payload {@see TodoGotoRejectedEventDto}.
 *
 * Пример использования:
 * ```php
 * TodoListLoggingSubscriber::register($logger);
 * ```
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
            self::resolveLogger($payload, $logger)->info(
                'Todo event: started | ' . (string) $payload,
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_COMPLETED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info(
                'Todo event: completed | ' . (string) $payload,
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_FAILED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->error(
                'Todo event: failed | ' . (string) $payload,
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_GOTO_REQUESTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info(
                'Todo event: goto_requested | ' . (string) $payload,
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_GOTO_REJECTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->warning(
                'Todo event: goto_rejected | ' . (string) $payload,
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_AGENT_SWITCHED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info(
                'Todo event: agent_switched | ' . (string) $payload,
                $payload->toArray()
            );
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

    /**
     * Возвращает логгер из конфигурации агента события или fallback-логгер.
     */
    private static function resolveLogger(TodoEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
