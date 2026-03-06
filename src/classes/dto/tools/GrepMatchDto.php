<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

/**
 * DTO одного совпадения при поиске текста в файлах ({@see \app\modules\neuron\tools\GrepTool}).
 *
 * Описывает конкретное место, где был найден искомый паттерн: файл, номер строки,
 * полное содержимое строки и непосредственно совпавший фрагмент. Длинные строки
 * и совпадения обрезаются до безопасных лимитов (500 и 200 символов соответственно)
 * ещё на этапе формирования в GrepTool.
 *
 * Формат сериализации (toArray):
 * ```
 * [
 *     'filePath'    => string,  // относительный путь к файлу
 *     'lineNumber'  => int,     // номер строки (1-based)
 *     'lineContent' => string,  // содержимое строки (может быть усечено)
 *     'matchText'   => string,  // совпавший фрагмент (может быть усечён)
 * ]
 * ```
 */
final class GrepMatchDto
{
    /**
     * @param string $filePath    Путь к файлу с совпадением (относительный)
     * @param int    $lineNumber  Номер строки совпадения (1-based)
     * @param string $lineContent Полное содержимое строки с совпадением
     * @param string $matchText   Найденный фрагмент текста
     */
    public function __construct(
        public readonly string $filePath,
        public readonly int $lineNumber,
        public readonly string $lineContent,
        public readonly string $matchText,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{filePath: string, lineNumber: int, lineContent: string, matchText: string}
     */
    public function toArray(): array
    {
        return [
            'filePath' => $this->filePath,
            'lineNumber' => $this->lineNumber,
            'lineContent' => $this->lineContent,
            'matchText' => $this->matchText,
        ];
    }
}
