<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use app\modules\neuron\enums\StatusCheckCleanupDecision;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use app\modules\neuron\helpers\LlmCycleStatusCheckHelper;
use app\modules\neuron\helpers\StatusCheckHistoryCleanupHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

/**
 * Тесты классификации ответа проверки статуса и очистки истории waitCycle.
 *
 * Тестируемые сущности: {@see LlmCycleStatusCheckHelper}, {@see StatusCheckHistoryCleanupHelper}.
 */
final class LlmCycleStatusCheckHelperTest extends TestCase
{
    /**
     * Граничное условие: null — чистку не выполняем (решение null).
     */
    public function testResolveCleanupDecisionNullAnswerReturnsNull(): void
    {
        $this->assertNull(LlmCycleStatusCheckHelper::resolveCleanupDecision(null));
    }

    /**
     * Структурированный не-message ответ — удаляем пару из истории.
     */
    public function testResolveCleanupDecisionStructuredObjectReturnsRemovePair(): void
    {
        $dto = new \stdClass();
        $dto->done = true;

        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($dto)
        );
    }

    /**
     * Пустой текст после trim — пара сообщений не нужна в логе.
     */
    public function testResolveCleanupDecisionEmptyStringReturnsRemovePair(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, '');

        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Только пробелы — как пустой ответ, удаляем пару.
     */
    public function testResolveCleanupDecisionWhitespaceOnlyReturnsRemovePair(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, "  \t\n  ");

        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Явное YES — удаляем пару служебных сообщений.
     */
    public function testResolveCleanupDecisionExplicitYesReturnsRemovePair(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'YES, the task is done.');

        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Явное NO (регистр не важен) — удаляем пару.
     */
    public function testResolveCleanupDecisionExplicitNoReturnsRemovePair(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'no — still working');

        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Явное WAITING — удаляем пару.
     */
    public function testResolveCleanupDecisionExplicitWaitingReturnsRemovePair(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'WAITING for tool');

        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Неоднозначный ответ — убираем только пользовательский запрос проверки.
     */
    public function testResolveCleanupDecisionAmbiguousReturnsRemoveUserOnly(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'maybe, I need to think');

        $this->assertSame(
            StatusCheckCleanupDecision::RemoveUserOnly,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Слово KNOW не должно считаться явным NO (ложное срабатывание подстроки).
     */
    public function testKnowIsNotExplicitNo(): void
    {
        $this->assertFalse(LlmCycleStatusCheckHelper::hasExplicitStatusKeyword('I KNOW this'));

        $msg = new Message(MessageRole::ASSISTANT, 'I KNOW this');
        $this->assertSame(
            StatusCheckCleanupDecision::RemoveUserOnly,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * Непустой массив в getContent — пару не удаляем (решение null), чтобы не терять блочный/мультимодальный ответ.
     */
    public function testDecisionForNeuronRawNonEmptyArrayReturnsNull(): void
    {
        $this->assertNull(
            LlmCycleStatusCheckHelper::decisionForNeuronRawAndNormalizedText(['block'], '')
        );
    }

    /**
     * Пустой массив блоков — как пустой ответ, удаляем пару.
     */
    public function testDecisionForNeuronRawEmptyArrayReturnsRemovePair(): void
    {
        $this->assertSame(
            StatusCheckCleanupDecision::RemovePair,
            LlmCycleStatusCheckHelper::decisionForNeuronRawAndNormalizedText([], '')
        );
    }

    /**
     * Длинный текст без ключевых слов — неоднозначный ответ, оставляем только удаление user-запроса.
     */
    public function testResolveCleanupDecisionLongGibberishReturnsRemoveUserOnly(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, str_repeat('x', 500) . ' gibberish only');

        $this->assertSame(
            StatusCheckCleanupDecision::RemoveUserOnly,
            LlmCycleStatusCheckHelper::resolveCleanupDecision($msg)
        );
    }

    /**
     * InMemoryFullChatHistory: RemovePair снимает два последних сообщения после раунда.
     */
    public function testApplyFullHistoryRemovePairRemovesUserAndAssistant(): void
    {
        $history = new InMemoryFullChatHistory(contextWindow: 50_000);
        $history->addMessage(new Message(MessageRole::USER, 'task'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'done'));

        $before = ChatHistoryEditHelper::getFullMessageCount($history);
        $history->addMessage(new Message(MessageRole::USER, 'check'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'NO'));

        StatusCheckHistoryCleanupHelper::apply(
            $history,
            StatusCheckCleanupDecision::RemovePair,
            $before
        );

        $this->assertSame($before, ChatHistoryEditHelper::getFullMessageCount($history));
        $this->assertSame('done', $history->getFullLastMessage()->getContent());
    }

    /**
     * InMemoryChatHistory: RemoveUserOnly удаляет только запрос по индексу.
     */
    public function testApplySimpleHistoryRemoveUserOnlyKeepsAssistant(): void
    {
        $history = new InMemoryChatHistory(50_000);
        $history->addMessage(new Message(MessageRole::USER, 'context'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'ok'));

        $before = ChatHistoryTruncateHelper::getMessageCount($history);
        $history->addMessage(new Message(MessageRole::USER, 'status check'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'unclear'));

        StatusCheckHistoryCleanupHelper::apply(
            $history,
            StatusCheckCleanupDecision::RemoveUserOnly,
            $before
        );

        $this->assertSame(3, ChatHistoryTruncateHelper::getMessageCount($history));
        $msgs = $history->getMessages();
        $this->assertSame('unclear', $msgs[2]->getContent());
    }

    /**
     * deleteMessageAtIndex с неверным индексом — InvalidArgumentException.
     */
    public function testDeleteMessageAtIndexRejectsInvalidIndex(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new Message(MessageRole::USER, 'a'));

        $this->expectException(\InvalidArgumentException::class);
        ChatHistoryTruncateHelper::deleteMessageAtIndex($history, 1);
    }
}
