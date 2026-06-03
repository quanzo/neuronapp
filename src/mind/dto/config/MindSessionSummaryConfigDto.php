<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\dto\config;

/**
 * DTO настроек LLM-суммаризации сессий (`mind.session_summary` в config).
 *
 * Значение `null` у поля означает, что параметр не задан в конфигурации.
 *
 * Пример:
 *
 * <code>
 * $dto = MindSessionSummaryConfigDto::fromConfigArray([
 *     'agent' => 'my_summarizer_agent',
 *     'max_summary_chars' => 300,
 * ]);
 * $merged = MindSessionSummaryConfigDto::empty()->merge($dto);
 * </code>
 */
final class MindSessionSummaryConfigDto
{
    private const float MIN_RATIO = 0.05;

    private const float MAX_RATIO = 0.5;

    /**
     * @param string|null $agent            Имя агента-суммаризатора или null, если не задано.
     * @param int|null    $maxSummaryChars  Лимит длины summary в индексе или null.
     * @param float|null  $transcriptRatio  Доля окна под транскрипт или null.
     */
    public function __construct(
        private readonly ?string $agent = null,
        private readonly ?int $maxSummaryChars = null,
        private readonly ?float $transcriptRatio = null,
    ) {
    }

    /**
     * Пустая конфигурация (все параметры не заданы).
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Создаёт DTO из массива `session_summary` (PHP-конфиг агента или JSONC).
     *
     * @param array<string, mixed> $data Вложенный блок `session_summary`.
     */
    public static function fromConfigArray(array $data): self
    {
        if ($data === []) {
            return self::empty();
        }

        $agent = null;
        if (array_key_exists('agent', $data)) {
            $raw = trim((string) $data['agent']);
            $agent = $raw !== '' ? $raw : null;
        }

        $maxSummaryChars = null;
        if (array_key_exists('max_summary_chars', $data)) {
            $maxSummaryChars = (int) $data['max_summary_chars'];
        }

        $transcriptRatio = null;
        if (array_key_exists('transcript_ratio', $data)) {
            $transcriptRatio = (float) $data['transcript_ratio'];
        }

        return new self($agent, $maxSummaryChars, $transcriptRatio);
    }

    /**
     * Сливает настройки: non-null поля `$overlay` (агент) перекрывают `$this` (app).
     *
     * @param self|null $overlay Конфигурация агента или null (без изменений).
     */
    public function merge(?self $overlay): self
    {
        if ($overlay === null) {
            return new self($this->agent, $this->maxSummaryChars, $this->transcriptRatio);
        }

        return new self(
            $overlay->agent ?? $this->agent,
            $overlay->maxSummaryChars ?? $this->maxSummaryChars,
            $overlay->transcriptRatio ?? $this->transcriptRatio,
        );
    }

    /**
     * Возвращает имя агента-суммаризатора или пустую строку, если не задано.
     */
    public function resolveAgent(): string
    {
        if ($this->agent === null) {
            return '';
        }

        return trim($this->agent);
    }

    /**
     * Возвращает лимит длины summary с нижней границей 50 символов.
     *
     * @param int $default Значение по умолчанию при null.
     */
    public function resolveMaxSummaryChars(int $default = 300): int
    {
        $value = $this->maxSummaryChars ?? $default;
        if ($value < 50) {
            return 50;
        }

        return $value;
    }

    /**
     * Возвращает долю окна под транскрипт (clamp 0.05–0.5).
     *
     * @param float $default Значение по умолчанию при null.
     */
    public function resolveTranscriptRatio(float $default = 0.25): float
    {
        $ratio = $this->transcriptRatio ?? $default;
        if ($ratio < self::MIN_RATIO) {
            return self::MIN_RATIO;
        }
        if ($ratio > self::MAX_RATIO) {
            return self::MAX_RATIO;
        }

        return $ratio;
    }

    /**
     * Возвращает сырое значение agent (null = не задано).
     */
    public function getAgent(): ?string
    {
        return $this->agent;
    }

    /**
     * Возвращает сырое значение maxSummaryChars (null = не задано).
     */
    public function getMaxSummaryChars(): ?int
    {
        return $this->maxSummaryChars;
    }

    /**
     * Возвращает сырое значение transcriptRatio (null = не задано).
     */
    public function getTranscriptRatio(): ?float
    {
        return $this->transcriptRatio;
    }
}
