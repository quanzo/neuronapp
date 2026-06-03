<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events\subscribers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\helpers\LlmCycleHelper;
use app\modules\neuron\helpers\MindMessageBodyExportHelper;
use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\services\MindSessionSummaryService;
use app\modules\neuron\mind\storage\LegacyUserMindMigrator;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\storage\UserMindStorage;
use DateTimeImmutable;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use Throwable;

/**
 * Подписчик записи завершённых шагов LLM в долговременную память `.mind`.
 *
 * Реагирует на {@see EventNameEnum::AGENT_MESSAGE_COMPLETED}, фильтрует служебные сообщения цикла
 * {@see LlmCycleHelper} и пустые тексты, затем дописывает блоки через {@see UserMindStorage}.
 * Если в effective-конфиге `mind` выключен `collect` ({@see MindConfigDto::resolveCollect()},
 * merge app + agent с приоритетом агента), запись в `.mind` не выполняется.
 * Если у агента в DTO включено {@see ConfigurationAgent::isExcludeLongTermMind()}
 * (исполнение Skill/TodoList с `pure_context: true`, LLM-суммаризация сессий), запись в `.mind` не выполняется.
 * Служебные вызовы mind-summary используют отдельный sessionKey ({@see MindSummarySessionKeyHelper});
 * для таких ключей не вызывается {@see UserMindStorage::refreshSessionSummary()} (защита от зацикливания).
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
     * Глубина вложенных вызовов refreshSessionSummary (re-entrancy guard).
     */
    private static int $summaryRefreshDepth = 0;

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

                $app = ConfigurationApp::getInstance();
                $effectiveMind = MindConfigDto::resolveEffective($app, $agent);
                if (!$effectiveMind->resolveCollect(false)) {
                    return;
                }

                try {
                    $mindDir = $app->getMindDir();
                } catch (Throwable) {
                    return;
                }

                $userId = $app->getUserId();
                $sessionKey = $payload->getSessionKey();
                if ($sessionKey === '') {
                    $sessionKey = 'unknown';
                }

                $captured = self::parseCapturedAt($payload->getTimestamp());

                $paths = new MindPaths($mindDir, $userId);

                // Миграция legacy формата выполняется один раз: если есть legacy файлы и ещё нет sessions.md.
                $migrator = new LegacyUserMindMigrator($mindDir, $userId);
                if ($migrator->isMigrationNeeded()) {
                    $migrator->migrate();
                }

                $storage = new UserMindStorage($paths);
                $wrote = false;

                $wrote = self::tryAppendFromPayload($storage, $sessionKey, $payload->getOutgoingMessage(), $captured)
                    || $wrote;
                $wrote = self::tryAppendFromPayload($storage, $sessionKey, $payload->getIncomingMessage(), $captured)
                    || $wrote;

                // Summary пересчитываем не на каждое сообщение, чтобы не создавать лишние LLM-вызовы.
                // Текущая эвристика:
                // - если summary пустой — пробуем заполнить;
                // - иначе обновляем каждые 10 записей.
                if (
                    $wrote
                    && !MindSummarySessionKeyHelper::isSummarySession($sessionKey)
                    && self::$summaryRefreshDepth === 0
                ) {
                    $meta = $storage->getSessionsIndex()->get($sessionKey);
                    if ($meta !== null) {
                        $need = $meta->getSummary() === '' || ($meta->getMessageCount() % 10) === 0;
                        if ($need) {
                            ++self::$summaryRefreshDepth;
                            try {
                                $summaryService = MindSessionSummaryService::fromMindConfig($effectiveMind, $app);
                                $storage->refreshSessionSummary(
                                    $app,
                                    $sessionKey,
                                    $summaryService,
                                    $effectiveMind,
                                );
                            } finally {
                                --self::$summaryRefreshDepth;
                            }
                        }
                    }
                }
            },
            '*'
        );

        self::$isRegistered = true;
    }

    /**
     * Дописывает одно сообщение в per-session storage, если оно проходит фильтры цикла и непустого тела.
     *
     * @param UserMindStorage     $storage    Хранилище пользователя.
     * @param string              $sessionKey Ключ сессии.
     * @param NeuronMessage|null  $message    Сообщение LLM или null.
     * @param DateTimeImmutable   $captured   Время записи.
     *
     * @return bool true, если сообщение записано.
     */
    private static function tryAppendFromPayload(
        UserMindStorage $storage,
        string $sessionKey,
        ?NeuronMessage $message,
        DateTimeImmutable $captured,
    ): bool {
        if ($message === null) {
            return false;
        }

        if (LlmCycleHelper::isCycleEmptyMsg($message)) {
            return false;
        }

        if (LlmCycleHelper::isCycleRequestMsg($message) || LlmCycleHelper::isCycleResponseMsg($message)) {
            return false;
        }

        $body = trim(MindMessageBodyExportHelper::toStoragePlainBody($message));
        if ($body === '') {
            return false;
        }

        $storage->appendMessage($sessionKey, $message->getRole(), $body, $captured);

        return true;
    }

    /**
     * Сбрасывает флаг регистрации (для тестов).
     */
    public static function reset(): void
    {
        self::$isRegistered = false;
        self::$summaryRefreshDepth = 0;
    }

    /**
     * Возвращает текущую глубину вложенных refreshSessionSummary (для тестов).
     */
    public static function getSummaryRefreshDepth(): int
    {
        return self::$summaryRefreshDepth;
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
