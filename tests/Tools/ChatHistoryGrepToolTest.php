<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use app\modules\neuron\tools\ChatHistoryGrepTool;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * Тесты для {@see ChatHistoryGrepTool}.
 *
 * Проверяют:
 * - поиск простого текста в истории
 * - поиск по regex
 * - игнорирование регистра
 * - усечение по maxMatches
 * - обработку пустого паттерна
 * - нормализацию некорректного maxMatches
 */
final class ChatHistoryGrepToolTest extends TestCase
{
    private ConfigurationAgent $agentCfg;
    private InMemoryFullChatHistory $history;
    private ChatHistoryGrepTool $tool;

    /**
     * Создаёт историю с не менее чем 10 сообщениями, чтобы покрыть граничные сценарии.
     */
    protected function setUp(): void
    {
        $this->agentCfg = new ConfigurationAgent();
        $this->history = new InMemoryFullChatHistory(contextWindow: 50_000);

        // 12 сообщений: в том числе с несколькими строками.
        $this->history->addMessage(new Message(MessageRole::SYSTEM, "System: ready\nLine2"));
        $this->history->addMessage(new Message(MessageRole::USER, "First question"));
        $this->history->addMessage(new Message(MessageRole::ASSISTANT, "First answer\nContains KEYWORD here"));
        $this->history->addMessage(new Message(MessageRole::USER, "Second question\nwith keyword on new line"));
        $this->history->addMessage(new Message(MessageRole::ASSISTANT, "Second answer"));
        $this->history->addMessage(new Message(MessageRole::USER, "Third question"));
        $this->history->addMessage(new Message(MessageRole::ASSISTANT, "Regex target: foo   bar baz"));
        $this->history->addMessage(new Message(MessageRole::USER, "Noise message 1"));
        $this->history->addMessage(new Message(MessageRole::ASSISTANT, "Noise message 2"));
        $this->history->addMessage(new Message(MessageRole::USER, "Noise message 3"));
        $this->history->addMessage(new Message(MessageRole::ASSISTANT, "Noise message 4"));
        $this->history->addMessage(new Message(MessageRole::USER, "Tail message with keyword again"));

        $this->agentCfg->setChatHistory($this->history);

        $this->tool = (new ChatHistoryGrepTool())->setAgentCfg($this->agentCfg);
    }

    /**
     * Проверяет поиск простого текста по истории и корректное указание index/lineNumber.
     */
    public function testFindsPlainTextInHistory(): void
    {
        $json = ($this->tool)('keyword', caseInsensitive: true, includeToolMessages: true, maxMatches: 50);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('matches', $data);
        $this->assertSame(false, $data['truncated']);
        $this->assertSame(true, $data['caseInsensitive']);
        $this->assertGreaterThanOrEqual(3, $data['totalMatches']);

        // Одно из совпадений — в сообщении index=2, строка 2.
        $this->assertSame(2, $data['matches'][0]['index']);
        $this->assertSame(2, $data['matches'][0]['lineNumber']);
    }

    /**
     * Проверяет, что при caseInsensitive=false поиск чувствителен к регистру.
     */
    public function testCaseSensitiveDoesNotMatchDifferentCase(): void
    {
        $json = ($this->tool)('KEYWORD', caseInsensitive: false, includeToolMessages: true, maxMatches: 50);
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalMatches']);
        $this->assertSame(MessageRole::ASSISTANT->value, $data['matches'][0]['role']);
    }

    /**
     * Проверяет поиск по regex-паттерну.
     */
    public function testFindsRegexInHistory(): void
    {
        $json = ($this->tool)('/foo\\s+bar/', caseInsensitive: false, includeToolMessages: true, maxMatches: 50);
        $data = json_decode($json, true);

        $this->assertSame(1, $data['totalMatches']);
        $this->assertSame(6, $data['matches'][0]['index']);
        $this->assertStringContainsString('foo   bar', $data['matches'][0]['lineContent']);
    }

    /**
     * Проверяет усечение результатов при превышении maxMatches.
     */
    public function testMaxMatchesTruncation(): void
    {
        $json = ($this->tool)('Noise', caseInsensitive: false, includeToolMessages: true, maxMatches: 2);
        $data = json_decode($json, true);

        $this->assertTrue($data['truncated']);
        $this->assertCount(2, $data['matches']);
        $this->assertGreaterThanOrEqual(2, $data['totalMatches']);
    }

    /**
     * Проверяет, что пустой паттерн возвращает ошибку.
     */
    public function testEmptyPatternReturnsError(): void
    {
        $json = ($this->tool)('', caseInsensitive: false, includeToolMessages: true, maxMatches: 50);
        $data = json_decode($json, true);

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Проверяет, что maxMatches<=0 нормализуется минимум к 1.
     */
    public function testMaxMatchesNormalizedToAtLeastOne(): void
    {
        $json = ($this->tool)('Noise', caseInsensitive: false, includeToolMessages: true, maxMatches: 0);
        $data = json_decode($json, true);

        $this->assertCount(1, $data['matches']);
    }
}
