<?php

declare(strict_types=1);

namespace Tests\Tools;

use app\modules\neuron\tools\WebFetchTool;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see WebFetchTool}.
 *
 * Важно: тесты не должны делать реальные HTTP/LLM вызовы, поэтому мы используем
 * анонимные подклассы и переопределяем protected-методы:
 * - {@see WebFetchTool::fetchOnce()} — для симуляции HTTP-ответов и редиректов
 * - {@see WebFetchTool::applyPromptViaLlm()} — для симуляции LLM-обработки
 */
final class WebFetchToolTest extends TestCase
{
    /**
     * Проверяет, что при редиректе на другой host инструмент возвращает
     * сообщение-инструкцию (и не следует редиректу автоматически).
     */
    public function testRedirectToDifferentHostReturnsInstruction(): void
    {
        $tool = new class () extends WebFetchTool {
            protected function fetchOnce(string $url, int $timeoutMs, int $maxBodySize, bool $isHead = false): array
            {
                return [
                    'status' => 302,
                    'reason' => 'Found',
                    'headers' => [
                        'location' => 'https://evil.example/landing',
                        'content-type' => 'text/html; charset=utf-8',
                    ],
                    'contentType' => 'text/html; charset=utf-8',
                    'body' => '',
                ];
            }
        };

        $out = $tool->__invoke('extract', 'https://example.com/a', null, 'Summarize');

        $this->assertStringContainsString('REDIRECT DETECTED', $out);
        $this->assertStringContainsString('Redirect URL: https://evil.example/landing', $out);
        $this->assertStringContainsString('- prompt: "Summarize"', $out);
    }

    /**
     * Проверяет, что бинарный Content-Type возвращает ошибку.
     */
    public function testBinaryContentTypeReturnsError(): void
    {
        $tool = new class () extends WebFetchTool {
            protected function fetchOnce(string $url, int $timeoutMs, int $maxBodySize, bool $isHead = false): array
            {
                return [
                    'status' => 200,
                    'reason' => 'OK',
                    'headers' => [
                        'content-type' => 'application/pdf',
                    ],
                    'contentType' => 'application/pdf',
                    'body' => '%PDF-1.7 ...',
                ];
            }
        };

        $out = $tool->__invoke('extract', 'https://example.com/file.pdf', null, null);
        $this->assertStringStartsWith('Error: ', $out);
        $this->assertStringContainsString('Content-Type', $out);
    }

    /**
     * Проверяет кэширование: повторный вызов с теми же параметрами
     * не должен повторно вызывать fetchOnce().
     */
    public function testCacheReturnsSameResultWithoutSecondFetch(): void
    {
        $tool = new class () extends WebFetchTool {
            public int $fetchCalls = 0;

            protected function fetchOnce(string $url, int $timeoutMs, int $maxBodySize, bool $isHead = false): array
            {
                $this->fetchCalls++;
                return [
                    'status' => 200,
                    'reason' => 'OK',
                    'headers' => [
                        'content-type' => 'text/html; charset=utf-8',
                    ],
                    'contentType' => 'text/html; charset=utf-8',
                    'body' => '<h1>Hello</h1><p>World</p>',
                ];
            }
        };

        $first = $tool->__invoke('extract', 'https://example.com/page', null, null, null, null, 60);
        $second = $tool->__invoke('extract', 'https://example.com/page', null, null, null, null, 60);

        $this->assertSame($first, $second);
        $this->assertSame(1, $tool->fetchCalls);
    }

    /**
     * Проверяет ветку `prompt`: если prompt задан, результат должен быть
     * равен возврату applyPromptViaLlm() (а не сырым извлечённым текстом).
     */
    public function testPromptBranchUsesApplyPromptViaLlm(): void
    {
        $tool = new class () extends WebFetchTool {
            protected function fetchOnce(string $url, int $timeoutMs, int $maxBodySize, bool $isHead = false): array
            {
                return [
                    'status' => 200,
                    'reason' => 'OK',
                    'headers' => [
                        'content-type' => 'text/html; charset=utf-8',
                    ],
                    'contentType' => 'text/html; charset=utf-8',
                    'body' => '<h1>Title</h1><p>Body</p>',
                ];
            }

            protected function applyPromptViaLlm(string $prompt, string $content): string
            {
                return 'LLM:' . $prompt . '::' . $content;
            }
        };

        $out = $tool->__invoke('prompt', 'https://example.com/page', null, 'Extract title', null, null, 0);
        $this->assertStringStartsWith('LLM:Extract title::', $out);
        $this->assertStringContainsString('Title', $out);
    }
}
