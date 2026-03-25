<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\StoreToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function json_decode;
use function json_last_error;
use function trim;

use const JSON_ERROR_NONE;

/**
 * Инструмент `StoreSaveTool`: сохраняет результат по метке в `.store`.
 *
 * Назначение:
 * - дать LLM возможность сохранить важный результат (план, разобранный JSON, черновик)
 *   под короткой меткой, связанной с текущим `sessionKey`;
 * - данные могут быть как JSON-структурой, так и обычным текстом.
 *
 * Как использовать из LLM:
 * - выберите стабильную и понятную метку (`label`), например `"requirements"`, `"parsed_config"`;
 * - передавайте `data` как JSON-строку, когда это возможно (массив/объект), иначе — как текст.
 */
final class StoreSaveTool extends AStoreTool
{
    public function __construct(
        string $name = 'store_save',
        string $description = 'Сохраняет результат по метке в .store для текущего sessionKey. Пригодно для планов, разобранных структур, заметок.',
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
                description: 'Короткая и стабильная метка результата (например, "requirements", "draft_v2", "parsed_input"). Обязательна.',
                required: true,
            ),
            ToolProperty::make(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Краткое описание (1 строка) того, что сохранено. Обязательна. Пример: "План реализации StoreStorage", "Распарсенный конфиг из user input".',
                required: true,
            ),
            ToolProperty::make(
                name: 'data',
                type: PropertyType::STRING,
                description: 'Данные для сохранения. Рекомендуется JSON-строка ({"a":1} или ["x","y"]). Если JSON невалиден — сохраняется как обычный текст.',
                required: true,
            ),
        ];
    }

    /**
     * Сохраняет результат.
     *
     * @param string $label       Метка результата.
     * @param string $description Описание.
     * @param string $data        Данные (JSON-строка или текст).
     *
     * @return string JSON-результат.
     */
    public function __invoke(string $label, string $description, string $data): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();
        $labelTrimmed = trim($label);
        $descTrimmed  = trim($description);

        if ($labelTrimmed === '') {
            return $this->resultJson(new StoreToolResultDto(
                action    : 'save',
                success   : false,
                message   : 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        if ($descTrimmed === '') {
            return $this->resultJson(new StoreToolResultDto(
                action    : 'save',
                success   : false,
                message   : 'description не может быть пустым.',
                sessionKey: $sessionKey,
                label     : $labelTrimmed,
            ));
        }

        $payload = $this->parseDataString($data);

        try {
            $item = $storage->save($sessionKey, $labelTrimmed, $payload, $descTrimmed);
        } catch (\Throwable $e) {
            return $this->resultJson(new StoreToolResultDto(
                action    : 'save',
                success   : false,
                message   : 'Ошибка сохранения: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                label     : $labelTrimmed,
            ));
        }

        return $this->resultJson(new StoreToolResultDto(
            action     : 'save',
            success    : true,
            message    : 'Сохранено.',
            sessionKey : $sessionKey,
            label      : $item->label,
            fileName   : $item->fileName,
            description: $item->description,
            savedAt    : $item->savedAt,
            dataType   : $item->dataType,
        ));
    }

    /**
     * Пытается распарсить входную строку как JSON. Если не получилось — возвращает исходную строку.
     *
     * @param string $raw Входная строка.
     * @return mixed JSON-значение или исходная строка.
     */
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
