<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\mind\dto\config\MindConfigDto;
use app\modules\neuron\mind\dto\config\MindSessionSummaryConfigDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see MindConfigDto} и {@see MindSessionSummaryConfigDto} (`mind\dto\config`): merge и resolve.
 */
final class MindConfigDtoTest extends TestCase
{
    /**
     * Пустой fromConfigArray даёт все null.
     */
    public function testFromConfigArrayEmpty(): void
    {
        $dto = MindConfigDto::fromConfigArray([]);
        $this->assertNull($dto->getCollect());
        $this->assertNull($dto->getSessionSummary());
        $this->assertFalse($dto->resolveCollect());
    }

    /**
     * Полный блок mind разбирается корректно.
     */
    public function testFromConfigArrayFull(): void
    {
        $dto = MindConfigDto::fromConfigArray([
            'collect' => true,
            'session_summary' => [
                'agent' => 'summarizer',
                'max_summary_chars' => 200,
                'transcript_ratio' => 0.3,
            ],
        ]);
        $this->assertTrue($dto->getCollect());
        $summary = $dto->getSessionSummary();
        $this->assertNotNull($summary);
        $this->assertSame('summarizer', $summary->getAgent());
        $this->assertSame(200, $summary->getMaxSummaryChars());
        $this->assertSame(0.3, $summary->getTranscriptRatio());
    }

    /**
     * merge: agent collect=null сохраняет app collect=true.
     */
    public function testMergeCollectNullPreservesBase(): void
    {
        $app = MindConfigDto::fromConfigArray(['collect' => true]);
        $agent = MindConfigDto::fromConfigArray([]);
        $merged = $app->merge($agent);
        $this->assertTrue($merged->resolveCollect(false));
    }

    /**
     * merge: agent collect=false перекрывает app true.
     */
    public function testMergeCollectAgentOverrides(): void
    {
        $app = MindConfigDto::fromConfigArray(['collect' => true]);
        $agent = MindConfigDto::fromConfigArray(['collect' => false]);
        $merged = $app->merge($agent);
        $this->assertFalse($merged->resolveCollect(true));
    }

    /**
     * merge summary: agent agent B перекрывает app agent A.
     */
    public function testMergeSummaryAgentOverrides(): void
    {
        $app = MindConfigDto::fromConfigArray([
            'session_summary' => ['agent' => 'agent_a'],
        ]);
        $agent = MindConfigDto::fromConfigArray([
            'session_summary' => ['agent' => 'agent_b'],
        ]);
        $merged = $app->merge($agent);
        $this->assertSame('agent_b', $merged->resolveSessionSummary()->resolveAgent());
    }

    /**
     * merge summary: agent только max_chars — agent name из app сохраняется.
     */
    public function testMergeSummaryPartialOverlay(): void
    {
        $app = MindConfigDto::fromConfigArray([
            'session_summary' => [
                'agent' => 'agent_a',
                'max_summary_chars' => 300,
            ],
        ]);
        $agent = MindConfigDto::fromConfigArray([
            'session_summary' => [
                'max_summary_chars' => 100,
            ],
        ]);
        $merged = $app->merge($agent);
        $summary = $merged->resolveSessionSummary();
        $this->assertSame('agent_a', $summary->resolveAgent());
        $this->assertSame(100, $summary->resolveMaxSummaryChars());
    }

    /**
     * resolveCollect при всех null — default false.
     */
    public function testResolveCollectDefaultFalse(): void
    {
        $this->assertFalse(MindConfigDto::empty()->resolveCollect());
        $this->assertFalse(MindConfigDto::empty()->resolveCollect(false));
    }

    /**
     * resolveTranscriptRatio clamp в summary DTO.
     */
    #[DataProvider('provideTranscriptRatioClamp')]
    public function testResolveTranscriptRatioClamp(float $input, float $expected): void
    {
        $dto = MindSessionSummaryConfigDto::fromConfigArray(['transcript_ratio' => $input]);
        $this->assertSame($expected, $dto->resolveTranscriptRatio(0.25));
    }

    /**
     * @return iterable<string, array{0: float, 1: float}>
     */
    public static function provideTranscriptRatioClamp(): iterable
    {
        yield 'below_min' => [0.01, 0.05];
        yield 'above_max' => [0.9, 0.5];
        yield 'middle' => [0.3, 0.3];
        yield 'explicit_value' => [0.25, 0.25];
    }

    /**
     * merge null overlay — копия base.
     */
    public function testMergeNullOverlay(): void
    {
        $base = MindConfigDto::fromConfigArray(['collect' => true]);
        $merged = $base->merge(null);
        $this->assertTrue($merged->resolveCollect(false));
    }

    /**
     * resolveMaxSummaryChars минимум 50.
     */
    public function testResolveMaxSummaryCharsMin(): void
    {
        $dto = MindSessionSummaryConfigDto::fromConfigArray(['max_summary_chars' => 10]);
        $this->assertSame(50, $dto->resolveMaxSummaryChars());
    }

    /**
     * resolveAgent пустая строка при null.
     */
    public function testResolveAgentEmptyWhenNull(): void
    {
        $this->assertSame('', MindSessionSummaryConfigDto::empty()->resolveAgent());
    }

    /**
     * resolveTranscriptRatio использует default при null в DTO.
     */
    public function testResolveTranscriptRatioUsesDefaultWhenNull(): void
    {
        $this->assertSame(0.25, MindSessionSummaryConfigDto::empty()->resolveTranscriptRatio(0.25));
    }
}
