<?php

declare(strict_types=1);

namespace Tests\Neuron;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\summarize\SummarizeService;
use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see SummarizeService}.
 */
final class SummarizeServiceTest extends TestCase
{
    /**
     * При filterToolMessages=false tool-result должен попасть в transcript полностью, даже если getContent() пуст.
     */
    public function testToolResultIncludedWithFullPayloadWhenToolMessagesNotFiltered(): void
    {
        $agentCfg = $this->createMock(ConfigurationAgent::class);
        $history = new InMemoryFullChatHistory();

        $tool = $this->createMock(ToolInterface::class);
        $tool->method('jsonSerialize')->willReturn([
            'name' => 'test_tool',
            'description' => 'desc',
            'result' => ['ok' => true, 'data' => 'VALUE'],
        ]);

        $msg = new ToolResultMessage([$tool]);

        $svc = new SummarizeService(
            useSkill: false,
            skill: null,
            mode: 'replace_range',
            role: MessageRole::ASSISTANT,
            minTranscriptChars: 0,
            debug: false,
            logger: null,
            filterToolMessages: false,
            filterHistoryTools: false,
            minMessageChars: 0,
            dedupConsecutive: false,
            dedupTranscriptGlobal: false,
            excludeLlmCycleHelperPrompts: true,
        );

        // Моделируем, что tool-result уже попал в историю как часть "дельты" шага.
        $history->addMessage($msg);

        $before = 0;
        $after = 1;
        $svc->summarizeAndApply($agentCfg, $history, $before, $after, [$msg], 'test');

        $messages = method_exists($history, 'getFullMessages') ? $history->getFullMessages() : $history->getMessages();
        $this->assertNotEmpty($messages);
        $contents = array_map(static fn ($m) => (string) ($m->getContent() ?? ''), $messages);
        $joined = implode("\n", $contents);

        $this->assertStringContainsString('TOOL_RESULT', $joined);
        $this->assertStringContainsString('test_tool', $joined);
        $this->assertStringContainsString('VALUE', $joined);
    }
}
