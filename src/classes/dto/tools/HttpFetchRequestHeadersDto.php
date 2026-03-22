<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use InvalidArgumentException;

/**
 * Набор исходящих HTTP-заголовков для {@see \app\modules\neuron\tools\HttpFetchTool}.
 *
 * Хранит пары имя/значение с нормализацией ключа по нижнему регистру для слияния.
 * Значения очищаются от символов `\r` и `\n`, чтобы исключить подмену заголовков.
 *
 * Пример:
 *
 * ```php
 * $h = HttpFetchRequestHeadersDto::firefoxDefaults()
 *     ->withHeader('Authorization', 'Bearer token');
 * $block = $h->toStreamHeaderString();
 * ```
 */
final class HttpFetchRequestHeadersDto
{
    /**
     * Ключ — нижний регистр имени заголовка; в значении — исходное имя и очищенное значение.
     *
     * @var array<string, array{name: string, value: string}>
     */
    private array $entries;

    /**
     * @param array<string, array{name: string, value: string}> $entries
     */
    private function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    /**
     * Пустой набор заголовков (для последующего {@see self::withHeader()} и передачи в HttpFetchTool).
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Заголовки по умолчанию в стиле Firefox (User-Agent фиксированной версии).
     *
     * User-Agent соответствует Firefox 128.0 на Windows x64 (rv:128.0, Gecko/20100101).
     *
     * @return self
     */
    public static function firefoxDefaults(): self
    {
        $base = new self();

        return $base
            ->withHeader(
                'User-Agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0'
            )
            ->withHeader(
                'Accept',
                'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            )
            ->withHeader(
                'Accept-Language',
                'en-US,en;q=0.5'
            );
    }

    /**
     * Добавляет или заменяет заголовок (иммутабельная копия).
     *
     * Пустое имя после trim вызывает исключение. Значение очищается от CR/LF.
     *
     * @param string $name  Имя заголовка
     * @param string $value Значение заголовка
     *
     * @return self
     *
     * @throws InvalidArgumentException Если имя пустое
     */
    public function withHeader(string $name, string $value): self
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Имя HTTP-заголовка не может быть пустым.');
        }

        $key = strtolower($trimmedName);
        $next = $this->entries;
        $next[$key] = [
            'name' => $trimmedName,
            'value' => $this->sanitizeHeaderValue($value),
        ];

        return new self($next);
    }

    /**
     * Объединяет наборы: заголовки из `$other` перекрывают совпадающие по имени (без учёта регистра).
     *
     * @param self $other Второй набор заголовков
     *
     * @return self Новый экземпляр с объединёнными заголовками
     */
    public function merge(self $other): self
    {
        if ($other->entries === []) {
            return new self($this->entries);
        }

        $merged = $this->entries;
        foreach ($other->entries as $key => $entry) {
            $merged[$key] = $entry;
        }

        return new self($merged);
    }

    /**
     * Строка для опции `header` контекста потока PHP (`http`).
     *
     * Строки разделены `\r\n`, в конце — завершающий `\r\n` (если есть заголовки).
     *
     * @return string
     */
    public function toStreamHeaderString(): string
    {
        if ($this->entries === []) {
            return '';
        }

        $lines = [];
        foreach ($this->entries as $entry) {
            $lines[] = $entry['name'] . ': ' . $entry['value'];
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Удаляет из значения символы перевода строки, допускаемые в инъекции заголовков.
     *
     * @param string $value Сырое значение
     *
     * @return string Очищенное значение
     */
    private function sanitizeHeaderValue(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
