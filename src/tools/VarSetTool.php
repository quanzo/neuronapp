<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function json_decode;
use function json_last_error;
use function trim;

use const JSON_ERROR_NONE;

/**
 * Инструмент `VarSetTool`: установить переменную (результат) по метке в `.store`.
 */
final class VarSetTool extends AVarTool
{
    public function __construct(
        string $name = 'var_set',
        string $description = 'Установить значение переменной по ее имени.',
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
                name: 'name',
                type: PropertyType::STRING,
                description: 'Короткое и стабильное имя переменной (например, "requirements", "draft_v2", "parsed_input"). Обязательна.',
                required: true,
            ),
            ToolProperty::make(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Краткое описание (1 строка) того, что установлено. Обязательна.',
                required: true,
            ),
            ToolProperty::make(
                name: 'data',
                type: PropertyType::STRING,
                description: 'Данные для сохранения. Рекомендуется JSON-строка. Если JSON невалиден — сохраняется как обычный текст.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $name, string $description, string $data): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();
        $nameTrimmed = trim($name);
        $descTrimmed  = trim($description);

        if ($nameTrimmed === '') {
            return $this->resultJson(new VarToolResultDto(
                action    : 'set',
                success   : false,
                message   : 'name не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        if ($descTrimmed === '') {
            return $this->resultJson(new VarToolResultDto(
                action    : 'set',
                success   : false,
                message   : 'description не может быть пустым.',
                sessionKey: $sessionKey,
                name     : $nameTrimmed,
            ));
        }

        $payload = $this->parseDataString($data);

        try {
            $item = $storage->save($sessionKey, $nameTrimmed, $payload, $descTrimmed);
        } catch (\Throwable $e) {
            return $this->resultJson(new VarToolResultDto(
                action    : 'set',
                success   : false,
                message   : 'Ошибка сохранения: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                name     : $nameTrimmed,
            ));
        }

        return $this->resultJson(new VarToolResultDto(
            action     : 'set',
            success    : true,
            message    : 'OK',
            sessionKey : $sessionKey,
            name      : $item->name,
            fileName   : $item->fileName,
            description: $item->description,
            savedAt    : $item->savedAt,
            dataType   : $item->dataType,
        ));
    }

    private function parseDataString(string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw;
    }
}
