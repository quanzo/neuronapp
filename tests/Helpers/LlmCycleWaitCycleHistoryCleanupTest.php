<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use app\modules\neuron\helpers\ChatHistoryTruncateHelper;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты удаления служебных сообщений цикла ожидания (waitCycle) по дельте раунда.
 *
 * Тестируемая сущность: {@see LlmCycleHelper::cleanupCycleServiceMessagesBySnapshotRange}.
 */
final class LlmCycleWaitCycleHistoryCleanupTest extends TestCase
{
    /**
     * InMemoryChatHistory: удаляем служебный user-вопрос и служебный assistant-ответ.
     *
     * Важно: InMemoryChatHistory хранит только окно сообщений и может автоматически «выталкивать»
     * часть ленты при добавлении новых сообщений. Поэтому в этом тесте проверяем только удаление
     * служебной пары, без ожиданий по сохранению «своего» ответа (это покрыто full-history тестом).
     */
    public function testCleanupSimpleHistoryRemovesOnlyServiceMessagesFromDelta(): void
    {
        $history = new InMemoryChatHistory(50_000);
        $history->addMessage(new Message(MessageRole::USER, 'context'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'ok'));

        $before = ChatHistoryTruncateHelper::getMessageCount($history);

        $history->addMessage(new Message(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'NO'));

        $after = ChatHistoryTruncateHelper::getMessageCount($history);

        LlmCycleHelper::cleanupCycleServiceMessagesBySnapshotRange($history, $before, $after);

        $this->assertSame($before, ChatHistoryTruncateHelper::getMessageCount($history));
        $msgs = $history->getMessages();
        $this->assertSame('ok', $msgs[$before - 1]->getContent());
    }

    /**
     * InMemoryChatHistory: ToolCallMessage не удаляем, даже если он в дельте после служебного вопроса.
     */
    public function testCleanupSimpleHistoryKeepsToolCallMessage(): void
    {
        $history = new InMemoryChatHistory(50_000);
        $history->addMessage(new Message(MessageRole::USER, 'context'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'ok'));

        $before = ChatHistoryTruncateHelper::getMessageCount($history);

        $history->addMessage(new Message(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK));
        $history->addMessage(new ToolCallMessage('NO', []));

        $after = ChatHistoryTruncateHelper::getMessageCount($history);

        LlmCycleHelper::cleanupCycleServiceMessagesBySnapshotRange($history, $before, $after);

        $this->assertSame($before + 1, ChatHistoryTruncateHelper::getMessageCount($history));
        $msgs = $history->getMessages();
        $this->assertInstanceOf(ToolCallMessage::class, $msgs[$before]);
    }

    /**
     * InMemoryFullChatHistory: удаляем служебный user-вопрос и служебный assistant-ответ, оставляя «свой» ответ.
     */
    public function testCleanupFullHistoryRemovesOnlyServiceMessagesFromDelta(): void
    {
        $history = new InMemoryFullChatHistory(contextWindow: 50_000);
        $history->addMessage(new Message(MessageRole::USER, 'context'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'ok'));

        $before = ChatHistoryEditHelper::getFullMessageCount($history);

        $history->addMessage(new Message(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'WAITING'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'free-form answer'));

        $after = ChatHistoryEditHelper::getFullMessageCount($history);

        LlmCycleHelper::cleanupCycleServiceMessagesBySnapshotRange($history, $before, $after);

        $this->assertSame($before + 1, ChatHistoryEditHelper::getFullMessageCount($history));
        $this->assertSame('free-form answer', $history->getFullLastMessage()->getContent());
    }
}
