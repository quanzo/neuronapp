<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\neuron\history\FileFullChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see ChatHistoryEditHelper}.
 *
 * ChatHistoryEditHelper — редактирование полной истории сообщений (удаление/вставка по индексу)
 * с пересборкой окна и сохранением для файловых реализаций.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\ChatHistoryEditHelper}
 */
final class ChatHistoryEditHelperTest extends TestCase
{
    /** @var string Временная директория тестовой истории. */
    private string $tmpDir;

    /**
     * Создаёт уникальную директорию для каждого теста.
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuron_history_edit_' . uniqid('', true);
    }

    /**
     * getFullMessageCount() для пустой истории возвращает 0.
     */
    public function testGetFullMessageCountEmpty(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's1', contextWindow: 50);
        $this->assertSame(0, ChatHistoryEditHelper::getFullMessageCount($history));
    }

    /**
     * getMessages() для AbstractFullChatHistory возвращает полную историю (а не окно).
     */
    public function testGetMessagesPrefersFullHistory(): void
    {
        $history = new InMemoryFullChatHistory(contextWindow: 1);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));

        $messages = ChatHistoryEditHelper::getMessages($history);
        $this->assertCount(2, $messages);
        $this->assertSame('a', (string) $messages[0]->getContent());
        $this->assertSame('b', (string) $messages[1]->getContent());
    }

    /**
     * getMessages() для обычной истории возвращает окно (getMessages()).
     */
    public function testGetMessagesUsesWindowForRegularHistory(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));

        $messages = ChatHistoryEditHelper::getMessages($history);
        $this->assertCount(2, $messages);
        $this->assertSame('a', (string) $messages[0]->getContent());
        $this->assertSame('b', (string) $messages[1]->getContent());
    }

    /**
     * getLastMessages() при count <= 0 возвращает пустой массив.
     */
    public function testGetLastMessagesRejectsNonPositiveCount(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));

        $this->assertSame([], ChatHistoryEditHelper::getLastMessages($history, 0));
        $this->assertSame([], ChatHistoryEditHelper::getLastMessages($history, -1));
    }

    /**
     * getLastMessages() возвращает последний элемент при count=1.
     */
    public function testGetLastMessagesCountOne(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));

        $messages = ChatHistoryEditHelper::getLastMessages($history, 1);
        $this->assertCount(1, $messages);
        $this->assertSame('c', (string) $messages[0]->getContent());
    }

    /**
     * getLastMessages() при count больше размера возвращает всю историю.
     */
    public function testGetLastMessagesReturnsAllWhenCountExceedsSize(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));

        $messages = ChatHistoryEditHelper::getLastMessages($history, 99);
        $this->assertCount(3, $messages);
        $this->assertSame('a', (string) $messages[0]->getContent());
        $this->assertSame('b', (string) $messages[1]->getContent());
        $this->assertSame('c', (string) $messages[2]->getContent());
    }

    /**
     * getLastMessages() возвращает последние N сообщений в исходном порядке (старые → новые).
     */
    public function testGetLastMessagesKeepsChronologicalOrder(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'd'));

        $messages = ChatHistoryEditHelper::getLastMessages($history, 2);
        $this->assertCount(2, $messages);
        $this->assertSame('c', (string) $messages[0]->getContent());
        $this->assertSame('d', (string) $messages[1]->getContent());
    }

    /**
     * deleteFullMessageAt() с индексом -1 бросает InvalidArgumentException.
     */
    public function testDeleteRejectsNegativeIndex(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's2', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));

        $this->expectException(\InvalidArgumentException::class);
        ChatHistoryEditHelper::deleteFullMessageAt($history, -1);
    }

    /**
     * deleteFullMessageAt() с индексом >= count бросает InvalidArgumentException.
     */
    public function testDeleteRejectsTooLargeIndex(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's3', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));

        $this->expectException(\InvalidArgumentException::class);
        ChatHistoryEditHelper::deleteFullMessageAt($history, 1);
    }

    /**
     * insertFullMessageAt() с индексом -1 бросает InvalidArgumentException.
     */
    public function testInsertRejectsNegativeIndex(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's4', contextWindow: 50);

        $this->expectException(\InvalidArgumentException::class);
        ChatHistoryEditHelper::insertFullMessageAt($history, -1, new Message(MessageRole::USER, 'x'));
    }

    /**
     * insertFullMessageAt() с индексом > count бросает InvalidArgumentException.
     */
    public function testInsertRejectsTooLargeIndex(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's5', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));

        $this->expectException(\InvalidArgumentException::class);
        ChatHistoryEditHelper::insertFullMessageAt($history, 2, new Message(MessageRole::USER, 'x'));
    }

    /**
     * deleteFullMessageAt() удаляет первое сообщение и сохраняет изменение в файл.
     */
    public function testDeleteFirstMessagePersists(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's6', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));

        ChatHistoryEditHelper::deleteFullMessageAt($history, 0);
        $this->assertCount(1, $history->getFullMessages());
        $this->assertSame('b', $history->getFullMessages()[0]->getContent());

        // Проверяем перезагрузкой из файла.
        $reloaded = new FileFullChatHistory($this->tmpDir, 's6', contextWindow: 50);
        $this->assertCount(1, $reloaded->getFullMessages());
        $this->assertSame('b', $reloaded->getFullMessages()[0]->getContent());
    }

    /**
     * deleteFullMessageAt() удаляет сообщение из середины.
     */
    public function testDeleteMiddleMessage(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's7', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::USER, 'b'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));

        ChatHistoryEditHelper::deleteFullMessageAt($history, 1);
        $full = $history->getFullMessages();

        $this->assertCount(2, $full);
        $this->assertSame('a', $full[0]->getContent());
        $this->assertSame('c', $full[1]->getContent());
    }

    /**
     * deleteFullMessageAt() удаляет последнее сообщение.
     */
    public function testDeleteLastMessage(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's8', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::USER, 'b'));

        ChatHistoryEditHelper::deleteFullMessageAt($history, 1);
        $this->assertCount(1, $history->getFullMessages());
        $this->assertSame('a', $history->getFullMessages()[0]->getContent());
    }

    /**
     * deleteLastFullMessages() снимает несколько сообщений с конца полной истории.
     */
    public function testDeleteLastFullMessagesRemovesTail(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's12', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));

        ChatHistoryEditHelper::deleteLastFullMessages($history, 2);
        $full = $history->getFullMessages();

        $this->assertCount(1, $full);
        $this->assertSame('a', $full[0]->getContent());
    }

    /**
     * insertFullMessageAt() вставляет сообщение в начало.
     */
    public function testInsertAtBeginning(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's9', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'b'));

        ChatHistoryEditHelper::insertFullMessageAt($history, 0, new Message(MessageRole::USER, 'a'));
        $full = $history->getFullMessages();

        $this->assertCount(2, $full);
        $this->assertSame('a', $full[0]->getContent());
        $this->assertSame('b', $full[1]->getContent());
    }

    /**
     * insertFullMessageAt() вставляет сообщение в середину.
     */
    public function testInsertInMiddle(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's10', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));

        ChatHistoryEditHelper::insertFullMessageAt($history, 1, new Message(MessageRole::USER, 'b'));
        $full = $history->getFullMessages();

        $this->assertCount(3, $full);
        $this->assertSame('a', $full[0]->getContent());
        $this->assertSame('b', $full[1]->getContent());
        $this->assertSame('c', $full[2]->getContent());
    }

    /**
     * insertFullMessageAt() вставляет сообщение в конец (index == count).
     */
    public function testInsertAtEnd(): void
    {
        $history = new FileFullChatHistory($this->tmpDir, 's11', contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));

        ChatHistoryEditHelper::insertFullMessageAt($history, 1, new Message(MessageRole::USER, 'b'));
        $full = $history->getFullMessages();

        $this->assertCount(2, $full);
        $this->assertSame('a', $full[0]->getContent());
        $this->assertSame('b', $full[1]->getContent());
    }
}
