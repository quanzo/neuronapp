<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\classes\dto\tools\ChunckGrepResultDto;
use app\modules\neuron\helpers\MarkdownChunckHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function file_get_contents;
use function is_array;

/**
 * Инструмент поиска строки/regex в файле с возвратом семантических чанков markdown.
 *
 * Назначение
 * ----------
 * Дать LLM “умный grep по markdown”: вместо того чтобы возвращать голые строки совпадений,
 * инструмент отдаёт **контекст** в виде цельных семантических блоков markdown (заголовок+абзац,
 * таблица целиком, fenced-code целиком, списки и т.п.).
 *
 * Это удобно, когда LLM нужно:
 * - быстро понять смысл вокруг совпадения;
 * - не запрашивать весь файл целиком;
 * - избежать “порванного” контекста (например, половины таблицы).
 *
 * Как работает
 * ------------
 * 1) Валидирует путь/файл через {@see AChunckTool::validateTextFile()}:
 *    - path-traversal защита (файл должен быть внутри basePath);
 *    - файл должен существовать и быть читаемым;
 *    - файл должен быть **текстовым**;
 *    - размер файла ограничен $maxFileSize.
 *
 * 2) Читает файл целиком в память (под размерным лимитом, см. validateTextFile()).
 *
 * 3) Ищет совпадения **по строкам** и строит список **непересекающихся** чанков вокруг
 *    всех найденных якорных строк с помощью:
 *    {@see MarkdownChunckHelper::chunksAroundAllAnchorLineRegex()}.
 *
 * Входной паттерн $query
 * ----------------------
 * Параметр `query` может быть:
 * - корректным регулярным выражением *с разделителями* (например, `/^TODO:/u`);
 * - обычной строкой (например, `TODO:`) — в этом случае helper преобразует её
 *   в безопасный regex (экранирует спецсимволы и добавляет модификатор `u`).
 *
 * Ограничения и лимиты
 * --------------------
 * - `max_chars` — **суммарный** лимит на размер возвращаемого контента (в символах).
 * - `maxCharsPerBlock` — лимит на один чанк. В текущей реализации устанавливается как:
 *   `min(max_chars, 5000)` (5000 — дефолтный верхний предел на один блок).
 * - Если якорное совпадение находится внутри семантического блока, который больше лимита
 *   одного чанка, helper возвращает этот блок целиком с `isOversized=true`.
 *
 * Формат ответа
 * -------------
 * Возвращает JSON (LLM-friendly), структура соответствует {@see ChunckGrepResultDto}.
 *
 * Примеры использования
 * ---------------------
 * Поиск по обычной строке:
 *
 * <code>
 * $tool = new ChunckGrepTool(basePath: '/var/www/project');
 * $json = $tool('docs/spec.md', 'auth', 2000);
 * </code>
 *
 * Поиск по regex:
 *
 * <code>
 * $tool = new ChunckGrepTool();
 * $json = $tool('README.md', '/^##\\s+/u', 3000);
 * </code>
 *
 * Пример псевдо-вызова LLM:
 *
 * <code>
 * {"tool":"chunk_grep","args":{"path":"docs/tools.md","query":"chat_history","max_chars":2000}}
 * </code>
 */
final class ChunckGrepTool extends AChunckTool
{
    /**
     * @param string $basePath    Базовая директория (корень), относительно которой разрешаются пути.
     * @param int    $maxFileSize Максимальный размер файла (байт), допустимый для чтения этим инструментом.
     * @param string $name        Имя инструмента (как будет доступен LLM).
     * @param string $description Описание инструмента (для LLM).
     */
    public function __construct(
        string $basePath = '',
        int $maxFileSize = 10485760,
        string $name = 'chunk_grep',
        string $description = 'Поиск строки/regex в файле с возвратом семантических чанков/блоков.',
    ) {
        parent::__construct(
            basePath   : $basePath,
            maxFileSize: $maxFileSize,
            name       : $name,
            description: $description,
        );
    }

    /**
     * Описание входных параметров инструмента для LLM.
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            $this->makePathProperty(),
            ToolProperty::make(
                name       : 'query',
                type       : PropertyType::STRING,
                description: 'Строка поиска: регулярное выражение (с разделителями), обычный текст.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'max_chars',
                type       : PropertyType::INTEGER,
                description: 'Максимальный суммарный размер возвращаемого контента в символах. По умолчанию 20000.',
                required   : false,
            ),
        ];
    }

    /**
     * Выполняет поиск по файлу и возвращает семантические чанки вокруг совпадений.
     *
     * Важные детали:
     * - инструмент ищет совпадения **по строкам** (якорь = строка, которая матчится по query);
     * - возвращаемые блоки **целые** (не рвём таблицы/код/списки и т.п.);
     * - чанки не пересекаются по семантическим блокам;
     * - объём ответа ограничивается `max_chars`.
     *
     * @param string $path      Путь к файлу (абсолютный или относительный к basePath).
     * @param string|array $query     Строка поиска: regex (с разделителями) или обычный текст.
     * @param int|null $max_chars Максимальный суммарный размер возвращаемого контента (в символах). По умолчанию 20000.
     *
     * @return string JSON
     */
    public function __invoke(string $path, string|array $query, ?int $max_chars = null): string
    {
        $validated = $this->validateTextFile($path);
        if (!is_array($validated)) {
            return $validated;
        }

        if ($max_chars !== null && $max_chars <= 0) {
            return JsonHelper::encodeThrow([
                'error' => 'Параметр max_chars должен быть больше 0.',
            ]);
        }

        $max_chars = $max_chars ?? 20000;

        $content = file_get_contents($validated['resolvedPath']);
        if ($content === false) {
            return JsonHelper::encodeThrow([
                'error' => "Не удалось прочитать файл '{$path}'.",
            ]);
        }

        $maxCharsPerBlock = $max_chars > 5000 ? 5000 : $max_chars;
        if (is_string($query)) {
            $arQuery = explode('|', $query);
            if (sizeof($arQuery) == 1) {
                $arQuery = explode(',', $query);
            }
            $arQuery = array_filter(array_map(trim(...), $arQuery));
        } else {
            $arQuery = $query;
        }

        $chunksResult     = MarkdownChunckHelper::chunksAroundAllAnchorLineRegex(
            $content,
            $arQuery,
            $maxCharsPerBlock,
            $max_chars,
        );

        $dto = new ChunckGrepResultDto(
            filePath        : $path,
            query           : $query,
            maxTotalChars   : $max_chars,
            maxCharsPerBlock: $maxCharsPerBlock,
            chunks          : $chunksResult->chunks,
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }
}
