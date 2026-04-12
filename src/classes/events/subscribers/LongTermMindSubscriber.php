<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\storage\UserMindMarkdownStorage;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\helpers\LlmCycleHelper;
use app\modules\neuron\helpers\MindMessageBodyExportHelper;
use DateTimeImmutable;
use Throwable;

/**
 * Подписчик записи завершённых шагов LLM в долговременную память `.mind`.
 *
 * Реагирует на {@see EventNameEnum::AGENT_MESSAGE_COMPLETED}, фильтрует служебные сообщения цикла
 * {@see LlmCycleHelper} и пустые тексты, затем дописывает блоки через {@see UserMindMarkdownStorage}.
 * Если у агента в DTO включено {@see \app\modules\neuron\classes\config\ConfigurationAgent::isExcludeLongTermMind()}
 * (исполнение Skill/TodoList с `pure_context: true`), запись в `.mind` не выполняется.
 *
 * Пример:
 *
 * ```php
 * LongTermMindSubscriber::register();
 * ```
 */
final class LongTermMindSubscriber
{
    private static bool $isRegistered = false;

    /**
     * Регистрирует обработчик события `agent.message.completed`.
     */
    public static function register(): void
    {
        if (self::$isRegistered) {
            return;
        }

        EventBus::on(
            EventNameEnum::AGENT_MESSAGE_COMPLETED->value,
            static function (mixed $payload): void {
                if (!$payload instanceof AgentMessageEventDto) {
                    return;
                }

                $agent = $payload->getAgent();
                if ($agent !== null && $agent->isExcludeLongTermMind()) {
                    return;
                }

                try {
                    $mindDir = ConfigurationApp::getInstance()->getMindDir();
                } catch (Throwable) {
                    return;
                }

                $userId = ConfigurationApp::getInstance()->getUserId();
                $storage = new UserMindMarkdownStorage($mindDir, $userId);
                $sessionKey = $payload->getSessionKey();
                if ($sessionKey === '') {
                    $sessionKey = 'unknown';
                }

                $captured = self::parseCapturedAt($payload->getTimestamp());

                $user = $payload->getOutgoingMessage();
                if (
                    $user !== null
                    && !LlmCycleHelper::isCycleEmptyMsg($user)
                    && !LlmCycleHelper::isCycleRequestMsg($user)
                ) {
                    $body = trim(MindMessageBodyExportHelper::toStoragePlainBody($user));
                    if ($body !== '') {
                        $storage->appendMessage($sessionKey, $user->getRole(), $body, $captured);
                    }
                }

                $assistant = $payload->getIncomingMessage();
                if (
                    $assistant !== null
                    && !LlmCycleHelper::isCycleEmptyMsg($assistant)
                    && !LlmCycleHelper::isCycleResponseMsg($assistant)
                ) {
                    $body = trim(MindMessageBodyExportHelper::toStoragePlainBody($assistant));
                    if ($body !== '') {
                        $storage->appendMessage($sessionKey, $assistant->getRole(), $body, new DateTimeImmutable());
                    }
                }
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
     * Разбирает метку времени из DTO или возвращает «сейчас».
     *
     * @param string $timestamp Строка в формате ATOM либо пустая строка.
     */
    private static function parseCapturedAt(string $timestamp): DateTimeImmutable
    {
        if ($timestamp === '') {
            return new DateTimeImmutable();
        }

        $parsed = DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        if ($parsed === false) {
            return new DateTimeImmutable();
        }

        return $parsed;
    }
}
