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

                $logger->info('Tool event: started', $payload->toArray());
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::TOOL_COMPLETED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof ToolEventDto) {
                    return;
                }

                $logger->info('Tool event: completed', $payload->toArray());
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::TOOL_FAILED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof ToolEventDto) {
                    return;
                }

                $logger->error('Tool event: failed', $payload->toArray());
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
}
