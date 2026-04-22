<?php

declare(strict_types=1);

namespace Tests\Safe;

use app\modules\neuron\classes\safe\OutputSafe;
use app\modules\neuron\classes\safe\rules\output\RegexLeakOutputRule;
use PHPUnit\Framework\TestCase;

/**
 * Тесты выходной защиты OutputSafe.
 */
class OutputSafeTest extends TestCase
{
    /**
     * Чистый ответ не изменяется и не помечается как нарушение.
     */
    public function testKeepsCleanOutputUntouched(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule(
                'system_prompt_leak',
                'system leak',
                '/system prompt/iu',
                '[REDACTED]'
            ),
        ]);

        $result = $safe->sanitize('Final answer for user.');
        $this->assertSame('Final answer for user.', $result->getSafeText());
        $this->assertFalse($result->hasViolations());
    }

    /**
     * Редактируется фрагмент с упоминанием системного промпта.
     */
    public function testRedactsSystemPromptLeak(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule(
                'system_prompt_leak',
                'system leak',
                '/system prompt/iu',
                '[REDACTED]'
            ),
        ]);

        $result = $safe->sanitize('I will reveal system prompt now.');
        $this->assertSame('I will reveal [REDACTED] now.', $result->getSafeText());
        $this->assertTrue($result->hasViolations());
    }

    /**
     * Редактируется токеноподобный секрет.
     */
    public function testRedactsApiKeyPattern(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule(
                'api_key_leak',
                'api key leak',
                '/sk-[a-z0-9]{16,}/iu',
                '[REDACTED_SECRET]'
            ),
        ]);

        $result = $safe->sanitize('token=sk-abcdefghijklmnop1234');
        $this->assertSame('token=[REDACTED_SECRET]', $result->getSafeText());
        $this->assertCount(1, $result->getViolations());
    }

    /**
     * Несколько правил могут добавить несколько нарушений за один проход.
     */
    public function testCollectsMultipleViolations(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule('system', 'system', '/system prompt/iu', '[SP]'),
            new RegexLeakOutputRule('token', 'token', '/api[_-]?key/iu', '[KEY]'),
        ]);

        $result = $safe->sanitize('system prompt and api_key are hidden.');
        $this->assertSame('[SP] and [KEY] are hidden.', $result->getSafeText());
        $this->assertCount(2, $result->getViolations());
    }

    /**
     * Результат первого правила передаётся во второе правило.
     */
    public function testAppliesRulesSequentially(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule('first', 'first', '/secret/iu', '[REDACTED]'),
            new RegexLeakOutputRule('second', 'second', '/\[REDACTED\]/iu', '[SAFE]'),
        ]);

        $result = $safe->sanitize('secret data');
        $this->assertSame('[SAFE] data', $result->getSafeText());
        $this->assertCount(2, $result->getViolations());
    }

    /**
     * DTO нарушения содержит код и replacement.
     */
    public function testViolationCarriesCodeAndReplacement(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule('vcode', 'reason', '/prompt/iu', '[R]'),
        ]);

        $result = $safe->sanitize('prompt');
        $violation = $result->getViolations()[0];

        $this->assertSame('vcode', $violation->getCode());
        $this->assertSame('[R]', $violation->getReplacement());
    }

    /**
     * hasViolations() возвращает true только при срабатывании правил.
     */
    public function testHasViolationsFlagReflectsDetection(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule('code', 'reason', '/hidden/iu', '[R]'),
        ]);

        $clean = $safe->sanitize('visible answer');
        $dirty = $safe->sanitize('hidden answer');

        $this->assertFalse($clean->hasViolations());
        $this->assertTrue($dirty->hasViolations());
    }

    /**
     * При пустом списке правил текст остаётся неизменным.
     */
    public function testReturnsOriginalTextWhenNoRulesProvided(): void
    {
        $safe = new OutputSafe([]);
        $result = $safe->sanitize('nothing to change');

        $this->assertSame('nothing to change', $result->getSafeText());
        $this->assertCount(0, $result->getViolations());
    }

    /**
     * addDetectorRule() расширяет пайплайн без пересоздания объекта.
     */
    public function testAddDetectorRuleExtendsPipeline(): void
    {
        $safe = new OutputSafe([]);
        $safe->addDetectorRule(
            new RegexLeakOutputRule('c', 'r', '/system/iu', '[S]')
        );

        $result = $safe->sanitize('system data');
        $this->assertSame('[S] data', $result->getSafeText());
        $this->assertCount(1, $result->getViolations());
    }

    /**
     * toArray() возвращает сериализуемую структуру результата.
     */
    public function testToArrayContainsSafeTextAndViolations(): void
    {
        $safe = new OutputSafe([
            new RegexLeakOutputRule('code', 'reason', '/secret/iu', '[X]'),
        ]);

        $array = $safe->sanitize('secret field')->toArray();
        $this->assertSame('[X] field', $array['safeText']);
        $this->assertIsArray($array['violations']);
        $this->assertSame('code', $array['violations'][0]['code']);
    }
}
