<?php

declare(strict_types=1);

namespace Tests\Services;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\run\RunStateDto;
use app\modules\neuron\classes\neuron\history\FileFullChatHistory;
use app\modules\neuron\services\config\SessionConfigAppService;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Тесты для {@see SessionConfigAppService}.
 *
 * Проверяем базовые операции над сессиями:
 * - list/get/delete
 * - getStatus (по чекпоинту RunStateDto)
 * - getMessageCount
 * - deleteMessage/insertMessage
 * - getTrimmedHistory (копия истории, обрезанная заданным trimmer)
 *
 * Тестируемая сущность: {@see \app\modules\neuron\services\config\SessionConfigAppService}
 */
final class SessionConfigAppServiceTest extends TestCase
{
    /** @var string Временная директория окружения приложения. */
    private string $tmpDir;

    /**
     * Создаёт окружение и сбрасывает синглтон ConfigurationApp.
     */
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_sessions_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);

        $this->resetSingleton();

        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp);
    }

    /**
     * Сбрасывает синглтон и удаляет временную директорию.
     */
    protected function tearDown(): void
    {
        $this->resetSingleton();
        $this->removeDir($this->tmpDir);
    }

    /**
     * Сбрасывает приватное статическое свойство $instance через Reflection.
     */
    private function resetSingleton(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    /**
     * Рекурсивное удаление директории.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * list() возвращает найденную сессию, а getMessageCount() — корректный размер полной истории.
     */
    public function testListAndMessageCount(): void
    {
        $sessionKey = 's_list_1';
        $history = new FileFullChatHistory($this->tmpDir . '/.sessions', $sessionKey, contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'b'));

        $srv = new SessionConfigAppService(ConfigurationApp::getInstance());

        $items = $srv->list();
        $this->assertNotEmpty($items);
        $this->assertSame($sessionKey, $items[0]->getSessionKey());

        $this->assertSame(2, $srv->getMessageCount($sessionKey));
    }

    /**
     * getStatus() без чекпоинта возвращает runState=null.
     */
    public function testGetStatusWithoutCheckpoint(): void
    {
        $srv = new SessionConfigAppService(ConfigurationApp::getInstance());
        $status = $srv->getStatus('no_checkpoint');
        $this->assertNull($status->getRunState());
        $this->assertFalse($status->isRunning());
        $this->assertFalse($status->isFinished());
    }

    /**
     * getStatus() с чекпоинтом возвращает runState и отражает finished=false как isRunning=true.
     */
    public function testGetStatusWithCheckpointRunning(): void
    {
        $sessionKey = 's_status_1';

        $state = (new RunStateDto())
            ->setSessionKey($sessionKey)
            ->setAgentName(RunStateDto::DEF_AGENT_NAME)
            ->setRunId('run1')
            ->setTodolistName('todo')
            ->setStartedAt('2026-01-01T00:00:00Z')
            ->setLastCompletedTodoIndex(0)
            ->setHistoryMessageCount(2)
            ->setFinished(false);
        $state->write();

        $srv = new SessionConfigAppService(ConfigurationApp::getInstance());
        $status = $srv->getStatus($sessionKey);

        $this->assertNotNull($status->getRunState());
        $this->assertTrue($status->isRunning());
        $this->assertFalse($status->isFinished());
    }

    /**
     * deleteMessage()/insertMessage() меняют историю в файле.
     */
    public function testDeleteAndInsertMessagePersist(): void
    {
        $sessionKey = 's_edit_1';
        $history = new FileFullChatHistory($this->tmpDir . '/.sessions', $sessionKey, contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));
        $history->addMessage(new Message(MessageRole::USER, 'c'));

        $srv = new SessionConfigAppService(ConfigurationApp::getInstance());
        $srv->insertMessage($sessionKey, 1, new Message(MessageRole::USER, 'b'));
        $srv->deleteMessage($sessionKey, 0);

        $reloaded = new FileFullChatHistory($this->tmpDir . '/.sessions', $sessionKey, contextWindow: 50);
        $full = $reloaded->getFullMessages();

        $this->assertCount(2, $full);
        $this->assertSame('b', $full[0]->getContent());
        $this->assertSame('c', $full[1]->getContent());
    }

    /**
     * delete() удаляет файл истории сессии.
     */
    public function testDeleteSessionRemovesChatFile(): void
    {
        $sessionKey = 's_delete_1';
        $history = new FileFullChatHistory($this->tmpDir . '/.sessions', $sessionKey, contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'a'));

        $srv = new SessionConfigAppService(ConfigurationApp::getInstance());
        $srv->delete($sessionKey);

        $this->assertFileDoesNotExist($this->tmpDir . '/.sessions/neuron_' . $sessionKey . '.chat');
    }

    /**
     * getTrimmedHistory() возвращает копию истории, не меняя исходный файл, а окно строит через trimmer.
     */
    public function testGetTrimmedHistoryReturnsCopyAndUsesTrimmer(): void
    {
        $sessionKey = 's_trim_1';
        $history = new FileFullChatHistory($this->tmpDir . '/.sessions', $sessionKey, contextWindow: 50);
        $history->addMessage(new Message(MessageRole::USER, 'm0'));
        $history->addMessage(new Message(MessageRole::USER, 'm1'));
        $history->addMessage(new Message(MessageRole::USER, 'm2'));

        $srv = new SessionConfigAppService(ConfigurationApp::getInstance());

        $trimmer = new class implements HistoryTrimmerInterface {
            public function getTotalTokens(): int
            {
                return 0;
            }

            public function trim(array $messages, int $contextWindow): array
            {
                // Возвращаем только последнее сообщение.
                return $messages === [] ? [] : [ $messages[count($messages) - 1] ];
            }
        };

        $copy = $srv->getTrimmedHistory($sessionKey, $trimmer, contextWindow: 50);

        $this->assertCount(3, $copy->getFullMessages());
        $this->assertCount(1, $copy->getMessages());
        $this->assertSame('m2', $copy->getMessages()[0]->getContent());

        // Исходная история на диске не меняется.
        $reloaded = new FileFullChatHistory($this->tmpDir . '/.sessions', $sessionKey, contextWindow: 50);
        $this->assertCount(3, $reloaded->getFullMessages());
    }
}
