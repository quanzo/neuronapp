<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\dto\events\SkillEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use Psr\Log\LoggerInterface;

/**
 * Подписчик логирования skill-событий.
 */
final class SkillLoggingSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчики skill-событий.
     */
    public static function register(LoggerInterface $logger): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(EventNameEnum::SKILL_STARTED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof SkillEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info('Skill event: started', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::SKILL_COMPLETED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof SkillEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->info('Skill event: completed', $payload->toArray());
        }, '*');

        EventBus::on(EventNameEnum::SKILL_FAILED->value, static function (mixed $payload) use ($logger): void {
            if (!$payload instanceof SkillEventDto) {
                return;
            }
            self::resolveLogger($payload, $logger)->error('Skill event: failed', $payload->toArray());
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

    private static function resolveLogger(SkillEventDto $payload, LoggerInterface $fallbackLogger): LoggerInterface
    {
        $agentCfg = $payload->getAgent();
        if ($agentCfg !== null) {
            return $agentCfg->getLoggerWithContext();
        }

        return $fallbackLogger;
    }
}
