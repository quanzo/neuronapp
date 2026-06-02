<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\LongTermMindSubscriber;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\SessionMindMarkdownStorage;
use app\modules\neuron\mind\storage\UserMindStorage;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты изоляции mind-summary: exclude и служебный sessionKey не засоряют основную сессию.
 */
final class MindSessionSummaryIsolationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_mind_iso_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.mind', 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        mkdir($this->tmpDir . '/.logs', 0777, true);
        mkdir($this->tmpDir . '/.store', 0777, true);

        $dp = new DirPriority([$this->tmpDir]);
        file_put_contents($this->tmpDir . '/config.jsonc', "{\"mind\":{\"collect\":true}}\n");
        ConfigurationApp::init($dp, 'config.jsonc', 502);

        EventBus::clear();
        LongTermMindSubscriber::reset();
        LongTermMindSubscriber::register();
    }

    protected function tearDown(): void
    {
        LongTermMindSubscriber::reset();
        $this->removeTree($this->tmpDir);
    }

    /**
     * excludeLongTermMind + служебный sessionKey: в основной сессии записей нет.
     */
    public function testExcludeAndSummarySessionKeyDoNotWriteToMainSession(): void
    {
        $mainKey = '20260602-iso-main-' . uniqid();
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainKey);

        $agent = new ConfigurationAgent();
        $agent->setConfigurationApp(ConfigurationApp::getInstance());
        $agent->setSessionKey($summaryKey);
        $agent->setExcludeLongTermMind(true);

        $dto = (new AgentMessageEventDto())
            ->setSessionKey($summaryKey)
            ->setTimestamp('2026-06-02T12:00:00+00:00')
            ->setAgent($agent)
            ->setOutgoingMessage(new NeuronMessage(MessageRole::USER, 'Транскрипт для summary'))
            ->setIncomingMessage(new NeuronMessage(MessageRole::ASSISTANT, 'Краткое резюме'));

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto);

        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 502);
        $mainStorage = new SessionMindMarkdownStorage($paths, $mainKey);
        $summaryStorage = new SessionMindMarkdownStorage($paths, $summaryKey);

        $this->assertNull($mainStorage->getByRecordId(1));
        $this->assertNull($summaryStorage->getByRecordId(1));
    }

    /**
     * Запись в служебную сессию без exclude не увеличивает messageCount основной сессии.
     */
    public function testWriteToSummarySessionDoesNotTouchMainSession(): void
    {
        $mainKey = '20260602-iso-split-' . uniqid();
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainKey);

        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 502);
        $mind = new UserMindStorage($paths);

        $mind->appendMessage($mainKey, 'user', 'Первое сообщение основной сессии');
        $metaBefore = $mind->getSessionsIndex()->get($mainKey);
        $this->assertNotNull($metaBefore);
        $countBefore = $metaBefore->getMessageCount();

        $agent = new ConfigurationAgent();
        $agent->setConfigurationApp(ConfigurationApp::getInstance());
        $agent->setSessionKey($summaryKey);

        $dto = (new AgentMessageEventDto())
            ->setSessionKey($summaryKey)
            ->setTimestamp('2026-06-02T12:01:00+00:00')
            ->setAgent($agent)
            ->setOutgoingMessage(new NeuronMessage(MessageRole::USER, 'Служебный промпт'))
            ->setIncomingMessage(new NeuronMessage(MessageRole::ASSISTANT, 'Служебный ответ'));

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto);

        $metaAfter = $mind->getSessionsIndex()->get($mainKey);
        $this->assertNotNull($metaAfter);
        $this->assertSame($countBefore, $metaAfter->getMessageCount());

        $summaryStorage = new SessionMindMarkdownStorage($paths, $summaryKey);
        $this->assertNotNull($summaryStorage->getByRecordId(1));
    }

    /**
     * refreshSessionSummary для служебного ключа — no-op (без LLM и без изменения индекса MAIN).
     */
    public function testRefreshSessionSummarySkipsSummarySessionKey(): void
    {
        $mainKey = '20260602-iso-refresh-' . uniqid();
        $summaryKey = MindSummarySessionKeyHelper::forMainSession($mainKey);

        $paths = new MindPaths(ConfigurationApp::getInstance()->getMindDir(), 502);
        $mind = new UserMindStorage($paths);
        $mind->appendMessage($mainKey, 'user', 'Контент');
        $meta = $mind->getSessionsIndex()->get($mainKey);
        $this->assertNotNull($meta);
        $this->assertSame('', $meta->getSummary());

        $app = ConfigurationApp::getInstance();
        $mind->refreshSessionSummary($app, $summaryKey);

        $metaAfter = $mind->getSessionsIndex()->get($mainKey);
        $this->assertNotNull($metaAfter);
        $this->assertSame('', $metaAfter->getSummary());
    }

    /**
     * После обработки события глубина re-entrancy guard сброшена в ноль.
     */
    public function testSummaryRefreshDepthResetAfterEvent(): void
    {
        $mainKey = '20260602-iso-depth-' . uniqid();

        $agent = new ConfigurationAgent();
        $agent->setConfigurationApp(ConfigurationApp::getInstance());
        $agent->setSessionKey($mainKey);

        $dto = (new AgentMessageEventDto())
            ->setSessionKey($mainKey)
            ->setTimestamp('2026-06-02T12:02:00+00:00')
            ->setAgent($agent)
            ->setOutgoingMessage(new NeuronMessage(MessageRole::USER, 'Шаг 1'))
            ->setIncomingMessage(new NeuronMessage(MessageRole::ASSISTANT, 'Ответ 1'));

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto);

        $this->assertSame(0, LongTermMindSubscriber::getSummaryRefreshDepth());
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
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
