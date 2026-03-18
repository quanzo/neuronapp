<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use app\modules\neuron\classes\storage\IntermediateStorage;
use app\modules\neuron\classes\config\ConfigurationApp;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function count;
use function explode;
use function json_encode;
use function is_string;
use function array_slice;
use function implode;
use function max;
use function min;
use function trim;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент `IntermediateLoadTool`: загружает ранее сохранённый промежуточный результат по метке.
 *
 * Назначение:
 * - позволить LLM вернуть в контекст ранее сохранённый результат (по тому же `sessionKey`),
 *   не запрашивая пользователя и не повторяя вычисления;
 * - удобно для последовательных шагов (сперва разобрать данные, затем использовать результат).
 */
final class IntermediateLoadTool extends AIntermediateTool
{
    public function __construct(
        string $name = 'intermediate_load',
        string $description = 'Загружает ранее сохранённый промежуточный результат по метке для текущего sessionKey.',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * Описание входных параметров инструмента для LLM.
     *
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'label',
                type: PropertyType::STRING,
                description: 'Метка ранее сохранённого результата. Должна совпадать с label, использованным при сохранении.',
                required: true,
            ),
            ToolProperty::make(
                name: 'start_line',
                type: PropertyType::INTEGER,
                description: 'Для строковых данных: номер начальной строки (1-based, включительно). Если не задан — с начала.',
                required: false,
            ),
            ToolProperty::make(
                name: 'end_line',
                type: PropertyType::INTEGER,
                description: 'Для строковых данных: номер конечной строки (1-based, включительно). Если не задан — до конца.',
                required: false,
            ),
        ];
    }

    /**
     * Загружает промежуточный результат по метке.
     *
     * @param string $label Метка результата.
     *
     * @return string JSON-результат (включает data при успехе).
     */
    public function __invoke(string $label, ?int $start_line = null, ?int $end_line = null): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();
        $labelTrimmed = trim($label);

        if ($labelTrimmed === '') {
            return $this->resultJson(new IntermediateToolResultDto(
                action    : 'load',
                success   : false,
                message   : 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $loaded = $storage->load($sessionKey, $labelTrimmed);
        if ($loaded === null) {
            return $this->resultJson(new IntermediateToolResultDto(
                action    : 'load',
                success   : false,
                message   : 'Не найдено.',
                sessionKey: $sessionKey,
                label     : $labelTrimmed,
                exists    : false,
            ));
        }

        $data = $loaded['data'] ?? null;
        $description = is_string($loaded['description'] ?? null) ? (string) $loaded['description'] : null;
        $savedAt = is_string($loaded['savedAt'] ?? null) ? (string) $loaded['savedAt'] : null;
        $dataType = is_string($loaded['dataType'] ?? null) ? (string) $loaded['dataType'] : null;

        if (($start_line !== null || $end_line !== null) && !is_string($data)) {
            return $this->resultJson(new IntermediateToolResultDto(
                action    : 'load',
                success   : false,
                message   : 'Диапазон строк поддерживается только для строковых данных.',
                sessionKey: $sessionKey,
                label     : $labelTrimmed,
                fileName  : $storage->resultFileName($sessionKey, $labelTrimmed),
                description: $description,
                savedAt   : $savedAt,
                dataType  : $dataType,
                exists    : true,
            ));
        }

        $startLineOut = null;
        $endLineOut = null;
        $totalLinesOut = null;
        $truncated = null;

        if (is_string($data) && ($start_line !== null || $end_line !== null)) {
            $allLines = explode("\n", $data);
            $totalLines = count($allLines);

            $effectiveStart = $start_line !== null ? max(1, $start_line) : 1;
            $effectiveEnd = $end_line !== null ? min($end_line, $totalLines) : $totalLines;

            if ($totalLines === 0) {
                $effectiveStart = 1;
                $effectiveEnd = 0;
            } elseif ($effectiveStart > $totalLines) {
                return $this->resultJson(new IntermediateToolResultDto(
                    action    : 'load',
                    success   : false,
                    message   : 'start_line превышает общее количество строк.',
                    sessionKey: $sessionKey,
                    label     : $labelTrimmed,
                    fileName  : $storage->resultFileName($sessionKey, $labelTrimmed),
                    description: $description,
                    savedAt   : $savedAt,
                    dataType  : $dataType,
                    totalLines: $totalLines,
                    exists    : true,
                ));
            }

            $selected = array_slice($allLines, $effectiveStart - 1, $effectiveEnd - $effectiveStart + 1);

            $maxLines = 2000;
            $truncated = count($selected) > $maxLines;
            if ($truncated) {
                $selected = array_slice($selected, 0, $maxLines);
                $effectiveEnd = $effectiveStart + $maxLines - 1;
            }

            $data = implode("\n", $selected);

            $startLineOut = $effectiveStart;
            $endLineOut = $effectiveEnd;
            $totalLinesOut = $totalLines;
        }

        return $this->resultJson(new IntermediateToolResultDto(
            action    : 'load',
            success   : true,
            message   : 'Загружено.',
            sessionKey: $sessionKey,
            label     : $labelTrimmed,
            fileName  : $storage->resultFileName($sessionKey, $labelTrimmed),
            description: $description,
            savedAt   : $savedAt,
            dataType  : $dataType,
            data      : $data,
            exists    : true,
            startLine : $startLineOut,
            endLine   : $endLineOut,
            totalLines: $totalLinesOut,
            truncated : $truncated,
        ));
    }
}
