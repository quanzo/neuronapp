<?php

declare(strict_types=1);

namespace app\modules\neuron\classes;

/**
 * Абстрактный компонент для работы с текстовыми промптами.
 *
 * Выполняет общую для различных компонентов (TodoList, Skill и др.)
 * обработку входного текста: нормализацию переводов строк, разбор
 * опционального блока опций и основного текстового блока (body).
 */
abstract class APromptComponent
{
    /**
     * Ассоциативный массив опций компонента.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * Основной текстовый блок (тело промпта) после разборки.
     */
    protected string $body = '';

    /**
     * Базовый конструктор, выполняющий разбор входного текста.
     *
     * @param string $input Полный текст промпта, включая опции и тело.
     */
    protected function __construct(string $input)
    {
        $this->parse($input);
    }

    /**
     * Нормализует входной текст и заполняет опции и тело.
     *
     * @param string $input Исходный текст промпта.
     */
    protected function parse(string $input): void
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $input);
        $normalized = rtrim($normalized, "\n");
        $lines = $normalized === '' ? [] : explode("\n", $normalized);

        [$optionsLines, $bodyLines] = $this->splitBlocks($lines);

        $this->options = $this->parseOptions($optionsLines);
        $this->body = $this->buildBody($bodyLines);
    }

    /**
     * Делит строки на блок опций и основной текстовый блок.
     *
     * Если разделители '-' отсутствуют, весь текст трактуется как тело.
     * При наличии одного разделителя всё, что после него, считается блоком опций.
     * При наличии двух и более разделителей опции между первым и вторым,
     * а тело — после второго разделителя.
     *
     * @param string[] $lines Все строки исходного текста.
     *
     * @return array{0: string[], 1: string[]} Пара: [строки опций, строки тела].
     */
    protected function splitBlocks(array $lines): array
    {
        $delimiterIndexes = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^-{3,}\s*$/', $line) === 1) {
                $delimiterIndexes[] = $index;
            }
        }

        if ($delimiterIndexes === []) {
            return [[], $lines];
        }

        $first = $delimiterIndexes[0];
        $second = $delimiterIndexes[1] ?? null;

        $optionsBlock = [];
        $bodyBlock = [];

        if ($second === null) {
            $optionsBlock = array_slice($lines, $first + 1);
        } else {
            $optionsBlock = array_slice($lines, $first + 1, $second - $first - 1);
            $bodyBlock = array_slice($lines, $second + 1);
        }

        return [$optionsBlock, $bodyBlock];
    }

    /**
     * Разбирает строки блока опций в ассоциативный массив.
     *
     * Каждая непустая строка вида "name: value" интерпретируется как опция.
     * Значение при необходимости декодируется как JSON.
     *
     * @param string[] $lines Строки блока опций.
     *
     * @return array<string, mixed> Массив опций.
     */
    protected function parseOptions(array $lines): array
    {
        $options = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $parts = explode(':', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$name, $rawValue] = $parts;
            $name = trim($name);
            $rawValue = trim($rawValue);

            if ($name === '') {
                continue;
            }

            $value = $this->decodeOptionValue($rawValue);

            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Собирает основной текстовый блок из набора строк.
     *
     * Ведущие и внутренние пустые строки сохраняются, хвостовые переводы строк
     * по окончании текста удаляются.
     *
     * @param string[] $lines Строки тела.
     */
    protected function buildBody(array $lines): string
    {
        if ($lines === []) {
            return '';
        }

        return rtrim(implode("\n", $lines), "\n");
    }

    /**
     * Пытается декодировать строковое значение опции как JSON.
     *
     * При успешном декодировании возвращает результат json_decode(),
     * иначе возвращает исходную строку.
     *
     * @param string $rawValue Строковое значение опции.
     *
     * @return mixed Декодированное значение или исходная строка.
     */
    protected function decodeOptionValue(string $rawValue)
    {
        if ($rawValue === '') {
            return $rawValue;
        }

        $firstChar = $rawValue[0];
        $looksLikeJson = strpbrk($firstChar, '{["tfn-0123456789') !== false;

        if ($looksLikeJson) {
            $decoded = json_decode($rawValue, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $rawValue;
    }

    /**
     * Возвращает массив опций, полученных из входного текста.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Возвращает основной текстовый блок (тело промпта).
     */
    protected function getBody(): string
    {
        return $this->body;
    }
}

