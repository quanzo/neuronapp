<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\TodoEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function trim;

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
            self::resolveLogger($payload, $logger)->info(
                self::buildTodoMessage('started', $payload),
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_COMPLETED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info(
                self::buildTodoMessage('completed', $payload),
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_FAILED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->error(
                self::buildTodoMessage('failed', $payload),
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_GOTO_REQUESTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info(
                self::buildTodoMessage('goto_requested', $payload),
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_GOTO_REJECTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->warning(
                self::buildTodoMessage('goto_rejected', $payload),
                $payload->toArray()
            );
        }, '*');

        EventBus::on(EventNameEnum::TODO_AGENT_SWITCHED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof TodoEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info(
                self::buildTodoMessage('agent_switched', $payload),
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

    private static function resolveLogger(TodoEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }

    private static function buildTodoMessage(string $status, TodoEventDto $payload): string
    {
        $todo = self::buildTodoPreview($payload->getTodo(), 250);
        return $todo === ''
            ? 'Todo event: ' . $status
            : 'Todo event: ' . $status . ': ' . $todo;
    }

    private static function buildTodoPreview(string $todo, int $maxLength): string
    {
        $todo = trim($todo);
        if ($todo === '') {
            return '';
        }

        $firstLine = preg_replace("/\r\n|\r/u", "\n", $todo) ?? $todo;
        $pos = strpos($firstLine, "\n");
        if ($pos !== false) {
            $firstLine = mb_substr($firstLine, 0, $pos);
        }

        $firstLine = preg_replace('/\s+/u', ' ', $firstLine) ?? $firstLine;
        $firstLine = trim($firstLine);

        if (mb_strlen($firstLine) <= $maxLength) {
            return $firstLine;
        }

        return mb_substr($firstLine, 0, $maxLength) . '...[truncated]';
    }
}
