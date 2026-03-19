<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\ViewChunkResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function fclose;
use function fgets;
use function fopen;
use function implode;
use function is_array;
use function is_resource;
use function json_encode;
use function mb_strlen;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент чтения текстового файла чанками строк.
 *
 * Позволяет LLM получать не весь файл целиком, а небольшой непрерывный фрагмент
 * (чанк), ограниченный:
 * - стартовым номером строки (0-based);
 * - количеством строк;
 * - максимальным размером чанка в символах.
 *
 * Строки не разрываются: если добавление очередной строки приводит к превышению
 * лимита по символам, эта строка становится последней в чанке и не включается.
 *
 * Безопасность:
 * - Путь проверяется через {@see FileSystemHelper::isPathSafe()}.
 * - Бинарные файлы автоматически отклоняются.
 * - Размер файла ограничен maxFileSize.
 *
 * Результат возвращается в виде JSON через {@see ViewChunkResultDto}.
 */
class ChunckViewTool extends AChunckTool
{
    /**
     * Максимальное кол-во вызовов в сессии одного агента этого инструмента
     *
     * @var integer|null
     */
    protected ?int $maxRuns = 50;

    /**
     * @param string $basePath    Базовая директория
     * @param int    $maxFileSize Максимальный размер файла (байт)
     * @param string $name        Имя инструмента
     * @param string $description Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        int $maxFileSize = 10485760,
        string $name = 'view_chunk',
        string $description = 'Чтение текстового файла чанком строк с ограничением по строкам и размеру чанка в символах.',
    ) {
        parent::__construct(
            basePath: $basePath,
            maxFileSize: $maxFileSize,
            name: $name,
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
                name: 'start_line',
                type: PropertyType::INTEGER,
                description: 'Номер первой строки чанка (0-based). По умолчанию 0.',
                required: false,
            ),
            ToolProperty::make(
                name: 'lines',
                type: PropertyType::INTEGER,
                description: 'Максимальное количество строк в чанке. По умолчанию — без ограничения.',
                required: false,
            ),
            ToolProperty::make(
                name: 'max_chars',
                type: PropertyType::INTEGER,
                description: 'Максимальный размер чанка в символах (суммарно по всем строкам). По умолчанию — без ограничения.',
                required: false,
            ),
        ];
    }

    /**
     * Читает чанк строк из текстового файла.
     *
     * @param string   $path       Путь к файлу
     * @param int|null $start_line Номер первой строки чанка (0-based)
     * @param int|null $lines      Максимальное число строк в чанке
     * @param int|null $max_chars  Максимальный размер чанка в символах
     *
     * @return string JSON-строка с результатом чтения
     */
    public function __invoke(
        string $path,
        ?int $start_line = null,
        ?int $lines = null,
        ?int $max_chars = null,
    ): string {
        $validated = $this->validateTextFile($path);
        if (!is_array($validated)) {
            return $validated;
        }

        $start    = $start_line !== null && $start_line > 0 ? $start_line : 0;
        $maxLines = $lines      !== null && $lines > 0 ? $lines : null;
        $maxChars = $max_chars  !== null && $max_chars > 0 ? $max_chars : null;

        $handle = @fopen($validated['resolvedPath'], 'rb');
        if ($handle === false || !is_resource($handle)) {
            return json_encode([
                'error' => "Не удалось открыть файл '{$path}' для чтения.",
            ], JSON_UNESCAPED_UNICODE);
        }

        $totalLines          = 0;
        $totalLength         = 0;
        $chunkLines          = [];
        $chunkLength         = 0;
        $currentIndex        = 0;
        $effectiveLineLength = 0;
        $end                 = $start;

        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                if (!feof($handle)) {
                    fclose($handle);
                    return json_encode([
                        'error' => "Ошибка чтения файла '{$path}'.",
                    ], JSON_UNESCAPED_UNICODE);
                }
                break;
            }

            $lineLength = mb_strlen($line);
            $totalLines++;
            $totalLength += $lineLength;

            if (
                (($maxChars !== null && $effectiveLineLength < $maxChars) || $maxChars === null)
                && $currentIndex >= $start
                && count($chunkLines) < $maxLines
            ) {
                $effectiveLineLength += $lineLength + 1;
                $chunkLines[]         = $line;
                $end = $currentIndex;
            }

            $currentIndex++;
        }

        fclose($handle);

        if ($start >= $totalLines) {
            return json_encode([
                'error' => sprintf(
                    "Начальная строка %d превышает общее количество строк в файле (%d).",
                    $start,
                    $totalLines,
                ),
            ], JSON_UNESCAPED_UNICODE);
        }

        $chunk = implode("\n", $chunkLines);
        $dto = new ViewChunkResultDto(
            filePath   : $path,
            chunk      : $chunk,
            startLine  : $start,
            endLine    : $end,
            chunkLength: $effectiveLineLength,
            totalLines : $totalLines,
            totalLength: $totalLength,
        );

        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }

    // setBasePath и setMaxFileSize унаследованы из AChunckTool
}
