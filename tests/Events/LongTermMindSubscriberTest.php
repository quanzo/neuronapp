<?php

declare(strict_types=1);

namespace Tests\Events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\events\AgentMessageEventDto;
use app\modules\neuron\classes\events\EventBus;
use app\modules\neuron\classes\events\subscribers\LongTermMindSubscriber;
use app\modules\neuron\classes\storage\UserMindMarkdownStorage;
use app\modules\neuron\enums\EventNameEnum;
use app\modules\neuron\enums\ChatHistoryCloneMode;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * При `mind.collect: false` в конфигурации приложения подписчик не пишет в `.mind`.
     */
    public function testWhenMindCollectDisabledNothingWritten(): void
    {
        $app = ConfigurationApp::getInstance();
        $ref = new \ReflectionProperty(ConfigurationApp::class, 'config');
        $ref->setAccessible(true);
        $ref->setValue($app, array_merge($app->getAll(), [
            'mind' => [
                'collect' => false,
            ],
        ]));

        $this->assertFalse($app->isLongTermMindCollectionEnabled());

        $normalUser  = new NeuronMessage(MessageRole::USER, 'Текст при выключенном сборе в конфиге');
        $assistantOk = new NeuronMessage(MessageRole::ASSISTANT, 'Ответ');

        $dto = (new AgentMessageEventDto())
            ->setSessionKey('20260602-mind-collect-off')
            ->setRunId('')
            ->setTimestamp('2026-06-02T10:00:00+00:00')
            ->setOutgoingMessage($normalUser)
            ->setIncomingMessage($assistantOk);

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto);

        $storage = new UserMindMarkdownStorage($app->getMindDir(), 501);
        $this->assertNull($storage->getByRecordId(1));
    }

    /**
     * Параметры для {@see self::testMindPersistenceRespectsExcludeLongTermMind()}: агент, флаг исключения,
     * тексты сообщений, ожидание наличия записи id=1, опции клона и сброса флага.
     *
     * @return \Generator<string, list<array<string, mixed>>>
     */
    public static function mindPersistenceExcludeMatrixProvider(): \Generator
    {
        // 1. Исключение из mind: обычный диалог не должен создать запись.
        yield 'exclude_true_blocks_normal_dialog' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => 'Вопрос один',
            'assistantBody'               => 'Ответ один',
            'expectRecord1Exists'         => false,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 2. Флаг выключен — запись появляется.
        yield 'exclude_false_persists' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => false,
            'userBody'                    => 'Вопрос два',
            'assistantBody'               => 'Ответ два',
            'expectRecord1Exists'         => true,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 3. Без агента в DTO — подписчик пишет как раньше.
        yield 'no_agent_on_dto_persists' => [[
            'attachAgent'                 => false,
            'excludeWhenAttached'         => null,
            'userBody'                    => 'Вопрос три',
            'assistantBody'               => 'Ответ три',
            'expectRecord1Exists'         => true,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 4. Исключение + служебный user цикла — записи нет (и фильтр, и флаг).
        yield 'exclude_true_plus_cycle_user' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => LlmCycleHelper::MSG_CHECK_WORK,
            'assistantBody'               => 'YES',
            'expectRecord1Exists'         => false,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 5. Без исключения служебный user не пишется — записи нет по-прежнему.
        yield 'exclude_false_cycle_user_filtered' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => false,
            'userBody'                    => LlmCycleHelper::MSG_CHECK_WORK,
            'assistantBody'               => 'YES',
            'expectRecord1Exists'         => false,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 6. Исключение + только user без входящего assistant — не пишем ничего.
        yield 'exclude_true_user_only_no_incoming' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => 'Только исходящее',
            'assistantBody'               => null,
            'expectRecord1Exists'         => false,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 7. Исключение + пустой user и ответ — не должно появиться записи.
        yield 'exclude_true_empty_user' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => '',
            'assistantBody'               => 'X',
            'expectRecord1Exists'         => false,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 8. Исключение + заведомо «плохой» user из пробелов — после trim пусто, записи нет.
        yield 'exclude_true_whitespace_user' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => "   \n\t  ",
            'assistantBody'               => 'Ответ',
            'expectRecord1Exists'         => false,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 9. cloneForSession сбрасывает флаг: на DTO клон без exclude — запись должна появиться.
        yield 'clone_clears_exclude_then_persists' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => 'После клона пишем',
            'assistantBody'               => 'Ок',
            'expectRecord1Exists'         => true,
            'useCloneWithoutExclude'      => true,
            'clearExcludeBeforeTrigger'   => false,
        ]];
        // 10. Флаг снят перед событием — запись снова разрешена.
        yield 'exclude_cleared_before_trigger_persists' => [[
            'attachAgent'                 => true,
            'excludeWhenAttached'         => true,
            'userBody'                    => 'Сняли флаг',
            'assistantBody'               => 'Ок',
            'expectRecord1Exists'         => true,
            'useCloneWithoutExclude'      => false,
            'clearExcludeBeforeTrigger'   => true,
        ]];
    }

    /**
     * Граничные случаи: {@see LongTermMindSubscriber} и {@see ConfigurationAgent::isExcludeLongTermMind()}.
     *
     * @param array{
     *     attachAgent: bool,
     *     excludeWhenAttached: bool|null,
     *     userBody: string,
     *     assistantBody: string|null,
     *     expectRecord1Exists: bool,
     *     useCloneWithoutExclude: bool,
     *     clearExcludeBeforeTrigger: bool
     * } $row
     */
    #[DataProvider('mindPersistenceExcludeMatrixProvider')]
    public function testMindPersistenceRespectsExcludeLongTermMind(array $row): void
    {
        $sessionKey = '20260601-mind-exclude-matrix-' . md5((string) json_encode($row));
        $userMsg    = new NeuronMessage(MessageRole::USER, $row['userBody']);
        $dto        = (new AgentMessageEventDto())
            ->setSessionKey($sessionKey)
            ->setRunId('')
            ->setTimestamp('2026-06-01T10:00:00+00:00')
            ->setOutgoingMessage($userMsg);

        if ($row['assistantBody'] !== null) {
            $dto->setIncomingMessage(new NeuronMessage(MessageRole::ASSISTANT, $row['assistantBody']));
        }

        if ($row['attachAgent']) {
            $agent = new ConfigurationAgent();
            $agent->setConfigurationApp(ConfigurationApp::getInstance());
            $agent->setSessionKey($sessionKey);
            if ($row['excludeWhenAttached'] === true) {
                $agent->setExcludeLongTermMind(true);
            } elseif ($row['excludeWhenAttached'] === false) {
                $agent->setExcludeLongTermMind(false);
            }
            if ($row['useCloneWithoutExclude']) {
                $agent = $agent->cloneForSession(ChatHistoryCloneMode::RESET_EMPTY);
            }
            if ($row['clearExcludeBeforeTrigger']) {
                $agent->setExcludeLongTermMind(false);
            }
            $dto->setAgent($agent);
        }

        EventBus::trigger(EventNameEnum::AGENT_MESSAGE_COMPLETED->value, '*', $dto);

        $storage = new UserMindMarkdownStorage(ConfigurationApp::getInstance()->getMindDir(), 501);
        $r1      = $storage->getByRecordId(1);
        if ($row['expectRecord1Exists']) {
            $this->assertNotNull($r1);
        } else {
            $this->assertNull($r1);
        }
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
