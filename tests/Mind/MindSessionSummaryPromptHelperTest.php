<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\mind\helpers\MindSessionSummaryPromptHelper;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see MindSessionSummaryPromptHelper}: промпт первичной свёртки и merge с PREVIOUS_SUMMARY.
 */
final class MindSessionSummaryPromptHelperTest extends TestCase
{
    private const string SAMPLE_TRANSCRIPT = "[user] Привет\n[assistant] Ответ";

    /**
     * Без previous — задача Collapse и только блок TRANSCRIPT.
     */
    public function testBuildUserPromptWithoutPreviousSummary(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt(self::SAMPLE_TRANSCRIPT, null, 25);

        $this->assertStringContainsString('Collapse the story into a summary', $prompt);
        $this->assertStringContainsString('#TRANSCRIPT', $prompt);
        $this->assertStringContainsString(self::SAMPLE_TRANSCRIPT, $prompt);
        $this->assertStringNotContainsString('PREVIOUS_SUMMARY', $prompt);
        $this->assertStringContainsString('__25__', $prompt);
    }

    /**
     * С previous — merge-формулировка и оба блока.
     */
    public function testBuildUserPromptWithPreviousSummary(): void
    {
        $previous = 'Пользователь назвал агента Neo.';
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt(self::SAMPLE_TRANSCRIPT, $previous, 30);

        $this->assertStringContainsString('Update and merge the session summary', $prompt);
        $this->assertStringContainsString('# PREVIOUS_SUMMARY', $prompt);
        $this->assertStringContainsString($previous, $prompt);
        $this->assertStringContainsString('#TRANSCRIPT', $prompt);
        $this->assertStringContainsString(self::SAMPLE_TRANSCRIPT, $prompt);
        $this->assertStringNotContainsString('Collapse the story', $prompt);
    }

    /**
     * Пустая строка previous трактуется как отсутствие summary.
     */
    public function testBuildUserPromptTreatsEmptyPreviousAsAbsent(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt(self::SAMPLE_TRANSCRIPT, '', 20);

        $this->assertStringContainsString('Collapse the story into a summary', $prompt);
        $this->assertStringNotContainsString('PREVIOUS_SUMMARY', $prompt);
    }

    /**
     * Пробелы в previous — как отсутствие summary.
     */
    public function testBuildUserPromptTreatsWhitespacePreviousAsAbsent(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt(self::SAMPLE_TRANSCRIPT, "  \t\n  ", 20);

        $this->assertStringContainsString('Collapse the story into a summary', $prompt);
        $this->assertStringNotContainsString('PREVIOUS_SUMMARY', $prompt);
    }

    /**
     * normalizePreviousSummary: null остаётся null.
     */
    public function testNormalizePreviousSummaryNull(): void
    {
        $this->assertNull(MindSessionSummaryPromptHelper::normalizePreviousSummary(null));
    }

    /**
     * normalizePreviousSummary: trim сохраняет непустой текст.
     */
    public function testNormalizePreviousSummaryTrims(): void
    {
        $this->assertSame(
            'Факт A',
            MindSessionSummaryPromptHelper::normalizePreviousSummary("  Факт A  \n"),
        );
    }

    /**
     * Длинное previous попадает в промпт целиком.
     */
    public function testBuildUserPromptIncludesLongPreviousSummary(): void
    {
        $long = str_repeat('А', 500);
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt('t', $long, 10);

        $this->assertStringContainsString($long, $prompt);
        $this->assertStringContainsString('Update and merge', $prompt);
    }

    /**
     * Спецсимволы в previous и transcript не ломают структуру блоков.
     */
    public function testBuildUserPromptPreservesSpecialCharacters(): void
    {
        $previous = 'Кавычки " и <tag>';
        $transcript = '[user] 100% & done';
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt($transcript, $previous, 15);

        $this->assertStringContainsString($previous, $prompt);
        $this->assertStringContainsString($transcript, $prompt);
    }

    /**
     * maxWords < 1 поднимается до 1.
     */
    public function testBuildUserPromptClampsMaxWordsToAtLeastOne(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt('x', null, 0);

        $this->assertStringContainsString('__1__', $prompt);
    }

    /**
     * Отрицательный maxWords — тот же clamp.
     */
    public function testBuildUserPromptClampsNegativeMaxWords(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt('x', 'old', -5);

        $this->assertStringContainsString('__1__', $prompt);
    }

    /**
     * Пробелы только вокруг previous — merge с обрезанным текстом.
     */
    public function testBuildUserPromptMergeUsesTrimmedPrevious(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt('t', '  Ядро  ', 12);

        $this->assertStringContainsString('Ядро', $prompt);
        $this->assertStringNotContainsString('  Ядро  ', $prompt);
        $this->assertStringContainsString('Update and merge', $prompt);
    }

    /**
     * Пустой transcript всё равно включается в блок TRANSCRIPT.
     */
    public function testBuildUserPromptWithEmptyTranscript(): void
    {
        $prompt = MindSessionSummaryPromptHelper::buildUserPrompt('', null, 5);

        $this->assertStringContainsString('#TRANSCRIPT', $prompt);
        $this->assertStringContainsString('```', $prompt);
    }
}
