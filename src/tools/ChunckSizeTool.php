<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\classes\dto\tools\SizeChunkResultDto;
use NeuronAI\Tools\ToolProperty;

use function fclose;
use function fgets;
use function fopen;
use function is_array;
use function is_resource;
use function mb_strlen;

/**
 * Инструмент получения размера текстового файла в символах и строках.
 *
 * Удобен для LLM, чтобы оценить объём файла перед чтением его частями
 * с помощью {@see ChunckViewTool} или других инструментов.
 *
 * Возвращает количество строк и символов только для текстовых файлов.
 */
class ChunckSizeTool extends AChunckTool
{
    /**
     * @param string $basePath    Базовая директория
     * @param string $name        Имя инструмента
     * @param string $description Описание инструмента
     */
    public function __construct(
        string $basePath = '',
        string $name = 'chunk_size',
        string $description = 'Получение размера текстового файла в символах и строках.',
    ) {
        parent::__construct(
            basePath   : $basePath,
            maxFileSize: 10485760,
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
        ];
    }

    /**
     * Возвращает количество строк и символов в текстовом файле.
     *
     * @param string $path Путь к файлу
     *
     * @return string JSON-строка с результатом
     */
    public function __invoke(string $path): string
    {
        $validated = $this->validateTextFile($path);
        if (!is_array($validated)) {
            return $validated;
        }

        $handle = @fopen($validated['resolvedPath'], 'rb');
        if ($handle === false || !is_resource($handle)) {
            return JsonHelper::encodeThrow([
                'error' => "Не удалось открыть файл '{$path}' для чтения.",
            ]);
        }

        $totalLines = 0;
        $totalLength = 0;

        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                if (!feof($handle)) {
                    fclose($handle);
                    return JsonHelper::encodeThrow([
                        'error' => "Ошибка чтения файла '{$path}'.",
                    ]);
                }
                break;
            }

            $totalLines++;
            $totalLength += mb_strlen($line);
        }

        fclose($handle);

        $dto = new SizeChunkResultDto(
            filePath   : $path,
            totalLines : $totalLines,
            totalLength: $totalLength,
        );

        return JsonHelper::encodeThrow($dto->toArray());
    }
}
