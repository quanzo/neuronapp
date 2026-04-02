<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use app\modules\neuron\enums\LlmCyclePollStatus;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;

/**
 * Циклы ожидания завершения задачи LLM и повтор итогового сообщения.
 *
 * Пример:
 *
 * <code>
 * LlmCycleHelper::waitCycle($sessionCfg);
 * LlmCycleHelper::repeateResultMsg($sessionCfg);
 * </code>
 */
class LlmCycleHelper
{
    /*
    public const MSG_CHECK_WORK = "Have you completed the all current task? Strict answer only `YES` or `NO`! If you're waiting, strict answer `WAITING` only! If your answer is `NO`, then continue execute!";
    */
    public const MSG_CHECK_WORK = "Ready to take on a new challenge? STRICT ANSWER ONLY `YES` or `NO`! If your answer is `NO`, then continue execute!";
    public const MSG_CONTINUE = 'Сontinue with the task';
    public const MSG_RESULT = 'Repeat the final message';
    public const MSG_CHECK_WORK2 = 'Is the task complete? Answer only YES or NO! If NO, continue!';

    /**
     * Нормализует роль сообщения (enum/строка) к нижнему регистру.
     *
     * @param NeuronMessage $message Сообщение NeuronAI.
     *
     * @return string|null 'user', 'assistant', ... либо null, если роль не строковая и не enum.
     */
    private static function normalizeRole(NeuronMessage $message): ?string
    {
        /** @var mixed $role */
        $role = $message->getRole();

        if ($role instanceof MessageRole) {
            return mb_strtolower($role->value);
        }

        if (\is_string($role) && $role !== '') {
            return mb_strtolower($role);
        }

        return null;
    }

    /**
     * Определяет «пустое» текстовое сообщение в истории.
     *
     * Пустым считается сообщение NeuronAI, которое не является tool-call и не содержит
     * структурированный (array/object) контент, а текстовый контент после trim пуст
     * (или контент отсутствует).
     *
     * Роль сообщения не имеет значения.
     *
     * Пример:
     *
     * <code>
     * $isEmpty = LlmCycleHelper::isCycleEmptyMsg($message);
     * </code>
     *
     * @param mixed $message Сообщение (обычно {@see NeuronMessage}) или иное значение.
     */
    public static function isCycleEmptyMsg(mixed $message): bool
    {
        if (!$message instanceof NeuronMessage) {
            return false;
        }

        if ($message instanceof ToolCallMessage) {
            return false;
        }

        /** @var mixed $content */
        $content = $message->getContent();
        if ($content === null) {
            return true;
        }

        if (\is_array($content) || \is_object($content)) {
            return false;
        }

        $text = trim((string) $content);

        return $text === '';
    }

    /**
     * Проверяет, что сообщение является служебным вопросом цикла проверки статуса (waitCycle).
     *
     * Служебным считается user-сообщение с ровно одним из ожидаемых текстов проверки.
     *
     * Пример:
     *
     * <code>
     * $isServiceQuestion = LlmCycleHelper::isCycleRequestMsg($message);
     * </code>
     *
     * @param mixed $message Сообщение (обычно {@see NeuronMessage}) или иное значение.
     */
    public static function isCycleRequestMsg(mixed $message): bool
    {
        if (!$message instanceof NeuronMessage) {
            return false;
        }

        $arPossibleRole = [MessageRole::DEVELOPER->value, MessageRole::USER->value, MessageRole::SYSTEM->value];

        if (!in_array(self::normalizeRole($message), $arPossibleRole)) {
            return false;
        }

        $content = $message->getContent();
        if (!\is_string($content)) {
            return false;
        }

        $text = trim($content);
        if ($text === '') {
            return false;
        }

        return \in_array($text, [self::MSG_CHECK_WORK, self::MSG_CHECK_WORK2], true);
    }

    /**
     * Проверяет, что сообщение является служебным ответом на вопрос цикла проверки статуса (waitCycle).
     *
     * Служебным считается assistant-сообщение, которое содержит явный статус-код (YES/NO/WAITING)
     * либо пустой текст (как «нет ответа» в текстовом канале).
     *
     * Пример:
     *
     * <code>
     * $isServiceAnswer = LlmCycleHelper::isCycleResponseMsg($message);
     * </code>
     *
     * @param mixed $message Сообщение (обычно {@see NeuronMessage}) или иное значение.
     */
    public static function isCycleResponseMsg(mixed $message): bool
    {
        if (!$message instanceof NeuronMessage) {
            return false;
        }

        if ($message instanceof ToolCallMessage) {
            return false;
        }

        if (self::normalizeRole($message) !== 'assistant') {
            return false;
        }

        /** @var mixed $content */
        $content = $message->getContent();
        if ($content === null) {
            return true;
        }

        if (\is_array($content)) {
            return false;
        }

        $text = trim((string) $content);
        if ($text === '') {
            return true;
        }

        return 1 === preg_match(
            '/(?<![A-Za-z])(YES|NO|WAITING)(?![A-Za-z])/i',
            $text
        );
    }

    /**
     * Удаляет из истории служебные сообщения цикла проверки статуса по диапазону снимков [before..after).
     *
     * Метод не делает truncate «хвоста»: удаляются только те сообщения в дельте, которые распознаны
     * как служебный запрос ({@see isCycleRequestMsg}) или служебный ответ ({@see isCycleResponseMsg}).
     *
     * Пример:
     *
     * <code>
     * $history = $agentCfg->getChatHistory();
     * $before = ChatHistoryRollbackHelper::getSnapshotCount($history);
     * $agentCfg->sendMessage($msg);
     * $after = ChatHistoryRollbackHelper::getSnapshotCount($history);
     * LlmCycleHelper::cleanupCycleServiceMessagesBySnapshotRange($history, $before, $after);
     * </code>
     *
     * @param ChatHistoryInterface $history История агента.
     * @param int $countBefore Число сообщений в истории до раунда.
     * @param int $countAfter Число сообщений в истории после раунда.
     */
    public static function cleanupCycleServiceMessagesBySnapshotRange(
        ChatHistoryInterface $history,
        int $countBefore,
        int $countAfter
    ): void {
        if ($countBefore < 0 || $countAfter <= $countBefore) {
            return;
        }

        $messages = ChatHistoryEditHelper::getMessages($history);

        $max = count($messages);
        if ($countBefore >= $max) {
            return;
        }

        $end = min($countAfter, $max);
        /** @var array<int,true> $indexesToDelete */
        $indexesToDelete = [];
        $awaitingServiceResponse = false;

        for ($i = $countBefore; $i < $end; $i++) {
            $msg = $messages[$i] ?? null;
            if ($msg === null) {
                continue;
            }

            if (self::isCycleEmptyMsg($msg)) {
                $indexesToDelete[$i] = true;
                continue;
            }

            if (self::isCycleRequestMsg($msg)) {
                $indexesToDelete[$i] = true;
                $awaitingServiceResponse = true;
                continue;
            }

            if ($awaitingServiceResponse && self::isCycleResponseMsg($msg)) {
                $indexesToDelete[$i] = true;
                $awaitingServiceResponse = false;
            }
        }

        if ($indexesToDelete === []) {
            return;
        }

        $indexes = array_keys($indexesToDelete);
        rsort($indexes);

        foreach ($indexes as $index) {
            if ($history instanceof AbstractFullChatHistory) {
                ChatHistoryEditHelper::deleteFullMessageAt($history, $index);
                continue;
            }

            ChatHistoryTruncateHelper::deleteMessageAtIndex($history, $index);
        }
    }

    /**
     * Цикл опроса LLM до подтверждения завершения задачи; служебные реплики проверки при необходимости убираются из истории.
     *
     * Явные ответы NO увеличивают счётчик «ясных» незавершений (не более $maxCycleCount).\n
     * Невнятные ответы не увеличивают этот счётчик, но увеличивают число раундов sendMessage; при превышении $maxTotalRounds цикл прерывается (защита от зацикливания).
     *
     * @param ConfigurationAgent $agentCfg        Конфигурация агента с историей сессии.
     * @param int                $maxCycleCount   Максимум явных ответов «ещё в работе» (NO).
     * @param int|null           $maxTotalRounds  Верхняя граница числа вызовов sendMessage в этом waitCycle; null — max(30, maxCycleCount * 6).
     *
     * @return array{ok: bool, cycles: int, clearProgressCount: int, totalRounds: int}
     */
    public static function waitCycle(ConfigurationAgent $agentCfg, int $maxCycleCount = 10, ?int $maxTotalRounds = null): array
    {
        $maxTotalRounds ??= max(30, $maxCycleCount * 6);

        $msgTest = new NeuronMessage(MessageRole::USER, self::MSG_CHECK_WORK);

        $clearProgressCount = 0;
        $totalRounds        = 0;
        $completed          = false;

        $history     = $agentCfg->getChatHistory();
        $countBefore = ChatHistoryRollbackHelper::getSnapshotCount($history);

        while ($totalRounds < $maxTotalRounds) {
            ++$totalRounds;

            $msgAnswer = $agentCfg->sendMessage($msgTest);
            $status = LlmCyclePollStatus::fromAgentAnswer($msgAnswer);

            if ($status === LlmCyclePollStatus::Completed) {
                $completed = true;
                break;
            }

            if ($status === LlmCyclePollStatus::InProgress) {
                ++$clearProgressCount;
                if ($clearProgressCount >= $maxCycleCount) {
                    break;
                }

                continue;
            }

            // Unclear: счётчик явных «в работе» не увеличиваем; totalRounds уже учтён.
        }

        $countAfter = ChatHistoryRollbackHelper::getSnapshotCount($history);
        self::cleanupCycleServiceMessagesBySnapshotRange($history, $countBefore, $countAfter);

        return [
            'ok'                 => $completed,
            'cycles'             => $totalRounds,
            'clearProgressCount' => $clearProgressCount,
            'totalRounds'        => $totalRounds,
        ];
    }

    /**
     * Запрашивает у LLM повтор итогового сообщения (ожидаемое последнее сообщение в истории по задачам).
     *
     * @param ConfigurationAgent $agentCfg Конфигурация агента.
     *
     * @return NeuronMessage|object|null Ответ агента.
     */
    public static function repeateResultMsg(ConfigurationAgent $agentCfg): mixed
    {
        $msgTest   = new NeuronMessage(MessageRole::USER, self::MSG_RESULT);
        $msgAnswer = $agentCfg->sendMessage($msgTest);

        return $msgAnswer;
    }
}
