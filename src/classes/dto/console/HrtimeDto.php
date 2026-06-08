<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

/**
 * DTO монотонного времени на основе hrtime (наносекунды как float).
 *
 * Единственный класс для представления времени в консольных LLM-командах.
 *
 * Пример:
 *
 * <code>
 * $started = HrtimeDto::now();
 * $ended = HrtimeDto::now();
 * $durationSeconds = $ended->subtract($started)->toSeconds();
 * </code>
 */
final class HrtimeDto
{
    /**
     * @param float $nanoseconds Значение hrtime(true) в наносекундах.
     */
    private function __construct(
        private float $nanoseconds,
    ) {
    }

    /**
     * Текущее монотонное время.
     */
    public static function now(): self
    {
        $ns = hrtime(true);

        return new self($ns !== false ? (float) $ns : 0.0);
    }

    /**
     * Явное значение в наносекундах (для тестов).
     *
     * @param float $nanoseconds Значение hrtime в наносекундах.
     */
    public static function fromNanoseconds(float $nanoseconds): self
    {
        return new self($nanoseconds);
    }

    /**
     * Значение в наносекундах hrtime.
     */
    public function getNanoseconds(): float
    {
        return $this->nanoseconds;
    }

    /**
     * Складывает два момента времени, возвращает новый экземпляр.
     */
    public function add(self $other): self
    {
        return new self($this->nanoseconds + $other->nanoseconds);
    }

    /**
     * Вычитает другое время; отрицательная дельта приводится к нулю.
     */
    public function subtract(self $other): self
    {
        $delta = $this->nanoseconds - $other->nanoseconds;
        if ($delta < 0) {
            $delta = 0.0;
        }

        return new self($delta);
    }

    /**
     * Переводит наносекунды в секунды (3 знака после запятой).
     */
    public function toSeconds(): float
    {
        return round($this->nanoseconds / 1_000_000_000.0, 3);
    }

    /**
     * Форматирует метку для md/txt вывода.
     *
     * @param string $key Имя поля (например, startedAt).
     */
    public function formatKeyValue(string $key): string
    {
        return $key . '=' . $this->nanoseconds;
    }

    /**
     * Сериализует метку для JSON (float, наносекунды).
     */
    public function toArray(): float
    {
        return $this->nanoseconds;
    }
}
