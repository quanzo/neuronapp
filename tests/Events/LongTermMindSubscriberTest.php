<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\LongTermMindSubscriber;
use app\modules\neuron\classes\storage\UserMindMarkdownStorage;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see LongTermMindSubscriber}: фильтрация служебных сообщений цикла LLM.
 */
class LongTermMindSubscriberTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_ltmind_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.mind', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents($this->tmpDir . '/config.jsonc', "{}\n");
        ConfigurationApp::init($dp, 'config.jsonc', 501);

        EventBus::clear();
        LongTermMindSubscriber::reset();
        LongTermMindSubscriber::register();
    }

    protected function tearDown(): void
    {
        EventBus::clear();
        LongTermMindSubscriber::reset();
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
        $this->removeTree($this->tmpDir);
    }

    /**
     * Служебный вопрос цикла не попадает в `.mind`, обычное user-сообщение — попадает.
     */
    public function testCycleRequestMessageIsNotStored(): void
    {
        $cycleUser = new NeuronMessage(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK);
        $normalUser = new NeuronMessage(MessageRole::USER, 'Реальный вопрос');
        $assistantCycle = new NeuronMessage(MessageRole::ASSISTANT, 'YES');

        $dto = (new AgentMessageEventDto())
            ->setSessionKey('20260412-120000-1-0')
            ->setRunId('')
            ->setTimestamp('2026-04-12T12:00:00+00:00')
            ->setOutgoingMessage($cycleUser)
            ->setIncomingMessage($assistantCycle);

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto);

        $storage = new UserMindMarkdownStorage(ConfigurationApp::getInstance()->getMindDir(), 501);
        $this->assertNull($storage->getByRecordId(1));

        $assistantOk = new NeuronMessage(MessageRole::ASSISTANT, 'Обычный ответ');

        $dto2 = (new AgentMessageEventDto())
            ->setSessionKey('20260412-120000-1-0')
            ->setRunId('')
            ->setTimestamp('2026-04-12T12:00:01+00:00')
            ->setOutgoingMessage($normalUser)
            ->setIncomingMessage($assistantOk);

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto2);

        $storage2 = new UserMindMarkdownStorage(ConfigurationApp::getInstance()->getMindDir(), 501);
        $r1 = $storage2->getByRecordId(1);
        $this->assertNotNull($r1);
        $this->assertStringContainsString('Реальный', $r1->getBody());
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            $f->isDir() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
