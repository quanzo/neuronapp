<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO результата чтения содержимого файла ({@see \app\modules\neuron\tools\ViewTool}).
 *
 * Содержит текст файла (или его фрагмент) с нумерацией строк в формате
 * `{номер}|{содержимое}`, а также метаинформацию о диапазоне строк.
 * Нумерация строк позволяет модели точно ссылаться на конкретные строки
 * при последующем вызове EditTool. Поле truncated = true, если содержимое
 * было обрезано из-за лимита maxLines.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *     'filePath'   => string,  // путь к файлу (как запрошен)
 *     'content'    => string,  // нумерованные строки, разделённые \n
 *     'startLine'  => int,     // первая строка в content (1-based)
 *     'endLine'    => int,     // последняя строка в content (1-based)
 *     'totalLines' => int,     // общее число строк в файле
 *     'truncated'  => bool,    // было ли содержимое обрезано
 * ]
 * ```
 */
final class ViewResultDto
{
    /**
     * @param string $filePath   Путь к файлу
     * @param string $content    Содержимое файла (с нумерацией строк)
     * @param int    $startLine  Номер первой возвращённой строки (1-based)
     * @param int    $endLine    Номер последней возвращённой строки (1-based)
     * @param int    $totalLines Общее количество строк в файле
     * @param bool   $truncated  Было ли содержимое усечено из-за лимита
     */
    public function __construct(
        public readonly string $filePath,
        public readonly string $content,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly int $totalLines,
        public readonly bool $truncated,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{filePath: string, content: string, startLine: int, endLine: int, totalLines: int, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'content' => $this->content,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'totalLines' => $this->totalLines,
            'truncated' => $this->truncated,
        ];
    }
}
