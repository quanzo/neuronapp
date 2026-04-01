<?php

declare(strict_types=1);

namespace Tests\Neuron;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\neuron\trimmers\CclCodeHistoryTrimmer;
use app\modules\neuron\classes\neuron\trimmers\ConfigurationAgentHistoryHeadSummarizer;
use app\modules\neuron\classes\neuron\trimmers\HistoryHeadSummarizerInterface;
use app\modules\neuron\classes\neuron\trimmers\TokenCounter;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;
use Tests\Support\SpyProvider;

/**
 * Тесты для {@see CclCodeHistoryTrimmer}.
 */
final class CclCodeHistoryTrimmerTest extends TestCase
{
    /** @var string Временная директория для тестовых файлов. */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_ccl_trimmer_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);

        $this->resetConfigurationAppSingleton();
        $dp = new DirPriority([$this->tmpDir]);
        ConfigurationApp::init($dp, 'config.jsonc');
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationAppSingleton();
        $this->removeDir($this->tmpDir);
        SpyProvider::reset();

        parent::tearDown();
    }

    /**
     * Сбрасывает приватное статическое свойство $instance через Reflection,
     * чтобы каждый тест начинался с чистого состояния.
     */
    private function resetConfigurationAppSingleton(): void
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
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testEmptyHistoryReturnsEmpty(): void
    {
        // Граничный случай: пустая история.
        $trimmer = new CclCodeHistoryTrimmer(new TokenCounter(), null);
        $result = $trimmer->trim([], 1000);

        $this->assertSame([], $result);
        $this->assertSame(0, $trimmer->getTotalTokens());
    }

    public function testHistorySmallerThanContextNotChanged(): void
    {
        // История меньше окна — никаких преобразований.
        $messages = [
            new Message(MessageRole::USER, 'Hello'),
            new Message(MessageRole::ASSISTANT, 'World'),
        ];

        $trimmer = new CclCodeHistoryTrimmer(new TokenCounter(), null);
        $result = $trimmer->trim($messages, 10_000);

        $this->assertCount(2, $result);
        $this->assertSame('Hello', $result[0]->getContent());
        $this->assertSame('World', $result[1]->getContent());
    }

    public function testMicrocompactClearsOldToolResultsButKeepsRecent(): void
    {
        // Проверяем microcompact: старые tool-result очищаются, последние N остаются.
        $tool1 = Tool::make('t1')->setResult('OLD_RESULT');
        $tool2 = Tool::make('t2')->setResult('NEW_RESULT');

        $messages = [
            new Message(MessageRole::USER, 'Start'),
            new ToolCallMessage(null, [$tool1]),
            new ToolResultMessage([$tool1]),
            new ToolCallMessage(null, [$tool2]),
            new ToolResultMessage([$tool2]),
            new Message(MessageRole::USER, 'End'),
        ];

        // keepRecentToolResults=1 => первый ToolResultMessage должен быть очищен.
        $trimmer = (new CclCodeHistoryTrimmer(new TokenCounter(), null))
            ->withKeepRecentToolResults(1)
            ->withClearedToolResultMarker('[CLEARED]');

        // Малое окно, чтобы точно пройти через microcompact (даже если потом будет hard fallback).
        $trimmer->trim($messages, 1);

        $this->assertSame('[CLEARED]', $tool1->getResult(), 'Старый tool-result должен быть очищен');
        $this->assertSame('NEW_RESULT', $tool2->getResult(), 'Последний tool-result не должен быть очищен');
    }

    public function testTailDoesNotSplitToolCallAndResultPair(): void
    {
        // Граничный случай: tail должен сохранять ToolCall/ToolResult как пару.
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = new Message(MessageRole::USER, "Head q{$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "Head a{$i}");
        }

        $tool = Tool::make('t')->setResult('RESULT');
        $messages[] = new ToolCallMessage(null, [$tool]);
        $messages[] = new ToolResultMessage([$tool]);
        $messages[] = new Message(MessageRole::USER, 'After tool');
        $messages[] = new Message(MessageRole::ASSISTANT, 'After tool answer');

        $summarizer = new class implements HistoryHeadSummarizerInterface {
            public function summarize(array $headMessages, int $contextWindow): ?Message
            {
                return new Message(MessageRole::DEVELOPER, 'summary');
            }
        };

        $trimmer = (new CclCodeHistoryTrimmer(new TokenCounter(), $summarizer))
            ->withTailRatio(0.4);

        $result = $trimmer->trim($messages, 80);

        $hasPair = false;
        for ($i = 0; $i < count($result) - 1; $i++) {
            if ($result[$i] instanceof ToolCallMessage && $result[$i + 1] instanceof ToolResultMessage) {
                $hasPair = true;
                break;
            }
        }

        $this->assertTrue($hasPair, 'ToolCall/ToolResult пара должна сохраняться в tail без разрыва');
    }

    public function testUsesSummarizerAndPrependsDeveloperSummaryWhenOverflow(): void
    {
        // Проверяем, что при переполнении окна триммер вызывает суммаризатор и добавляет DEVELOPER summary.
        $messages = [];
        for ($i = 0; $i < 20; $i++) {
            $messages[] = new Message(MessageRole::USER, "Q{$i}");
            $messages[] = new Message(MessageRole::ASSISTANT, "A{$i}");
        }
        $messages[] = new Message(MessageRole::USER, 'Tail Q');
        $messages[] = new Message(MessageRole::ASSISTANT, 'Tail A');

        $calls = 0;
        $summarizer = new class ($calls) implements HistoryHeadSummarizerInterface {
            public function __construct(private int &$calls)
            {
            }

            public function summarize(array $headMessages, int $contextWindow): ?Message
            {
                $this->calls++;
                return new Message(MessageRole::DEVELOPER, 'LLM summary');
            }
        };

        $trimmer = (new CclCodeHistoryTrimmer(new TokenCounter(), $summarizer))
            ->withTailRatio(0.5);

        $result = $trimmer->trim($messages, 120);

        $this->assertGreaterThanOrEqual(1, $calls, 'Суммаризатор должен вызываться при переполнении окна');
        $this->assertNotEmpty($result);
        $this->assertSame(MessageRole::DEVELOPER->value, $result[0]->getRole());
        $this->assertSame('LLM summary', $result[0]->getContent());
    }

    public function testConfigurationAgentSummarizerReplacesMediaBlocksWithMarkers(): void
    {
        // Проверяем media stripping: ImageContent/FileContent заменяются на текстовые маркеры в транскрипте.
        SpyProvider::reset();

        $agentCfg = new ConfigurationAgent();
        $agentCfg->enableChatHistory = false;
        $agentCfg->provider = new SpyProvider('summarizer');
        $agentCfg->instructions = 'base';

        $summarizer = (new ConfigurationAgentHistoryHeadSummarizer($agentCfg))
            ->withImageMarker('[IMG]')
            ->withDocumentMarker('[DOC]')
            ->withMaxLineChars(2000);

        $head = [
            (new Message(MessageRole::USER, 'See attachments'))
                ->addContent(new ImageContent('data:image/png;base64,AAAA', SourceType::BASE64))
                ->addContent(new FileContent('FILE_BYTES', SourceType::BASE64, 'application/pdf', 'a.pdf')),
        ];

        $summary = $summarizer->summarize($head, 10_000);
        $this->assertInstanceOf(Message::class, $summary);

        // SpyProvider возвращает последнюю user-реплику как assistant, поэтому summary содержит транскрипт.
        $txt = $summary->getContent() ?? '';
        $this->assertStringContainsString('[IMG]', $txt);
        $this->assertStringContainsString('[DOC]', $txt);
        $this->assertStringNotContainsString('base64,AAAA', $txt, 'Бинарный payload не должен попадать в транскрипт');
    }

    public function testConfigurationAgentBuildsCclCompactTrimmerFromConfig(): void
    {
        // Проверяем интеграцию: buildHistoryTrimmer выбирает ccl_compact по конфигу.
        file_put_contents(
            $this->tmpDir . '/config.jsonc',
            json_encode([
                'history' => [
                    'trimmer' => 'ccl_compact',
                    'ccl_compact' => [
                        'tail_ratio' => 0.7,
                        'keep_recent_tool_results' => 3,
                    ],
                ],
            ])
        );

        // Переинициализируем ConfigurationApp с уже созданным файлом.
        $this->resetConfigurationAppSingleton();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]), 'config.jsonc');

        $agentCfg = new ConfigurationAgent();
        $agentCfg->enableChatHistory = true;
        $agentCfg->contextWindow = 10_000;
        $agentCfg->setConfigurationApp(ConfigurationApp::getInstance());
        $agentCfg->setSessionKey('test-session');

        $ref = new \ReflectionClass($agentCfg);
        $m = $ref->getMethod('buildHistoryTrimmer');
        $m->setAccessible(true);
        $trimmer = $m->invoke($agentCfg);

        $this->assertInstanceOf(CclCodeHistoryTrimmer::class, $trimmer);
    }
}
