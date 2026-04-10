<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\BaseEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorErrorEventDto;
use app\modules\neuron\classes\dto\events\OrchestratorResumeHistoryMissingEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования событий оркестратора.
 *
 * Переводит в PSR-3 события, публикуемые {@see \app\modules\neuron\classes\orchestrators\TodoListOrchestrator}:
 * жизненный цикл (`cycle_started`, `step_completed`, `completed`, `restarted`) и
 * предупреждение `resume_history_missing`.
 *
 * - `orchestrator.cycle_started`, `orchestrator.step_completed`, `orchestrator.completed`
 *    ожидают payload {@see OrchestratorEventDto};
 * - `orchestrator.failed` ожидает payload {@see OrchestratorErrorEventDto};
 * - `orchestrator.restarted` ожидает payload {@see OrchestratorErrorEventDto} (с информацией об ошибке,
 *    вызвавшей рестарт);
 * - `orchestrator.resume_history_missing` ожидает payload {@see OrchestratorResumeHistoryMissingEventDto}.
 *
 * Пример использования:
 * ```php
 * OrchestratorLoggingSubscriber::register($logger);
 * ```
 */
final class OrchestratorLoggingSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчики событий оркестратора, связанных с логированием.
     *
     * @param LoggerInterface $logger Логгер по умолчанию, если в DTO нет агента с логгером.
     */
    public static function register(LoggerInterface $logger): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(
            EventNameEnum::ORCHESTRATOR_CYCLE_STARTED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof OrchestratorEventDto) {
                    return;
                }

                self::resolveLoggerFromBaseEventDto($payload, $logger)->info(
                    'Orchestrator event: cycle_started | ' . (string) $payload,
                    $payload->toArray()
                );
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::ORCHESTRATOR_STEP_COMPLETED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof OrchestratorEventDto) {
                    return;
                }

                self::resolveLoggerFromBaseEventDto($payload, $logger)->info(
                    'Orchestrator event: step_completed | ' . (string) $payload,
                    $payload->toArray()
                );
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::ORCHESTRATOR_COMPLETED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof OrchestratorEventDto) {
                    return;
                }

                self::resolveLoggerFromBaseEventDto($payload, $logger)->info(
                    'Orchestrator event: completed | ' . (string) $payload,
                    $payload->toArray()
                );
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::ORCHESTRATOR_FAILED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof OrchestratorEventDto) {
                    return;
                }

                self::resolveLoggerFromBaseEventDto($payload, $logger)->error(
                    'Orchestrator event: failed | ' . (string) $payload,
                    $payload->toArray()
                );
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::ORCHESTRATOR_RESTARTED->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof OrchestratorEventDto) {
                    return;
                }

                self::resolveLoggerFromBaseEventDto($payload, $logger)->warning(
                    'Orchestrator event: restarted | ' . (string) $payload,
                    $payload->toArray()
                );
            },
            '*'
        );

        EventBus::on(
            EventNameEnum::ORCHESTRATOR_RESUME_HISTORY_MISSING->value,
            static function (mixed $payload) use ($logger): void {
                if (!$payload instanceof OrchestratorResumeHistoryMissingEventDto) {
                    return;
                }

                self::resolveLoggerFromBaseEventDto($payload, $logger)->warning(
                    'Orchestrator event: resume_history_missing | ' . (string) $payload,
                    $payload->toArray()
                );
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
     * Возвращает логгер агента из DTO или fallback.
     */
    private static function resolveLoggerFromBaseEventDto(
        BaseEventDto $payload,
        LoggerInterface $fallbackLogger
    ): LoggerInterface {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
