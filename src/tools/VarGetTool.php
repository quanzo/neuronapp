<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_slice;
use function count;
use function explode;
use function implode;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Инструмент `VarGetTool`: получить переменную (результат) по имени.
 */
final class VarGetTool extends AVarTool
{
    public function __construct(
        string $name = 'var_get',
        string $description = 'Получает переменную по имени.',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name       : 'name',
                type       : PropertyType::STRING,
                description: 'Имя переменной, сохраненной ранее. Должна совпадать с `name`, использованным при установке.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'start_line',
                type       : PropertyType::INTEGER,
                description: 'Для строковых данных: номер начальной строки (1-based, включительно). Если не задан — с начала.',
                required   : false,
            ),
            ToolProperty::make(
                name       : 'end_line',
                type       : PropertyType::INTEGER,
                description: 'Для строковых данных: номер конечной строки (1-based, включительно). Если не задан — до конца.',
                required   : false,
            ),
        ];
    }

    public function __invoke(string $name, ?int $start_line = null, ?int $end_line = null): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();
        $nameTrimmed = trim($name);

        if ($nameTrimmed === '') {
            return $this->resultJson(new VarToolResultDto(
                action    : 'get',
                success   : false,
                message   : 'Имя не не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $loaded = $storage->load($sessionKey, $nameTrimmed);
        if ($loaded === null) {
            return $this->resultJson(new VarToolResultDto(
                action    : 'get',
                success   : false,
                message   : 'Не найдено.',
                sessionKey: $sessionKey,
                name     : $nameTrimmed,
                exists    : false,
            ));
        }

        $data        = $loaded['data'] ?? null;
        $description = is_string($loaded['description'] ?? null) ? (string) $loaded['description'] : null;
        $savedAt     = is_string($loaded['savedAt'] ?? null) ? (string) $loaded['savedAt'] : null;
        $dataType    = is_string($loaded['dataType'] ?? null) ? (string) $loaded['dataType'] : null;

        if (($start_line !== null || $end_line !== null) && !is_string($data)) {
            return $this->resultJson(new VarToolResultDto(
                action     : 'get',
                success    : false,
                message    : 'Диапазон строк поддерживается только для строковых данных.',
                sessionKey : $sessionKey,
                name       : $nameTrimmed,
                fileName   : $storage->resultFileName($sessionKey, $nameTrimmed),
                description: $description,
                savedAt    : $savedAt,
                dataType   : $dataType,
                exists     : true,
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
                return $this->resultJson(new VarToolResultDto(
                    action     : 'get',
                    success    : false,
                    message    : 'start_line превышает общее количество строк.',
                    sessionKey : $sessionKey,
                    name       : $nameTrimmed,
                    fileName   : $storage->resultFileName($sessionKey, $nameTrimmed),
                    description: $description,
                    savedAt    : $savedAt,
                    dataType   : $dataType,
                    totalLines : $totalLines,
                    exists     : true,
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

        return $this->resultJson(new VarToolResultDto(
            action     : 'get',
            success    : true,
            message    : 'OK',
            sessionKey : $sessionKey,
            name      : $nameTrimmed,
            fileName   : $storage->resultFileName($sessionKey, $nameTrimmed),
            description: $description,
            savedAt    : $savedAt,
            dataType   : $dataType,
            data       : $data,
            exists     : true,
            startLine  : $startLineOut,
            endLine    : $endLineOut,
            totalLines : $totalLinesOut,
            truncated  : $truncated,
        ));
    }
}
