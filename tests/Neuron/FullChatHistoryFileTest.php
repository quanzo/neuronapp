<?php

declare(strict_types=1);

namespace Tests\Neuron;

use app\modules\neuron\classes\neuron\history\FileFullChatHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see FileFullChatHistory}.
 */
final class FullChatHistoryFileTest extends TestCase
{
    public function testFullHistoryIsPersistedAndReloadedFromFile(): void
    {
        $dir = sys_get_temp_dir() . '/neuron_full_history_' . uniqid('', true);
        $key = 'session1';

        $history = new FileFullChatHistory($dir, $key, contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'Hello'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'World'));

        $this->assertTrue(file_exists($dir));

        $fullBefore = $history->getFullMessages();
        $this->assertCount(2, $fullBefore);

        // Пересоздаём объект, чтобы проверить загрузку из файла.
        $historyReloaded = new FileFullChatHistory($dir, $key, contextWindow: 50);
        $fullAfter = $historyReloaded->getFullMessages();

        $this->assertCount(2, $fullAfter);
        $this->assertSame('Hello', $fullAfter[0]->getContent());
        $this->assertSame('World', $fullAfter[1]->getContent());
    }

    public function testFlushAllClearsFileAndMemory(): void
    {
        $dir = sys_get_temp_dir() . '/neuron_full_history_' . uniqid('', true);
        $key = 'session2';

        $history = new FileFullChatHistory($dir, $key, contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'Hello'));

        $this->assertNotEmpty($history->getFullMessages());

        $history->flushAll();

        $this->assertSame([], $history->getFullMessages());
        $this->assertSame([], $history->getMessages());

        if (file_exists($dir)) {
            @rmdir($dir);
        }
    }
}
