<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use app\modules\neuron\helpers\IntermediateStorageHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function json_decode;
use function json_encode;
use function json_last_error;
use function trim;

use const JSON_ERROR_NONE;
use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент `IntermediateSaveTool`: сохраняет промежуточный результат по метке в `.store`.
 *
 * Назначение:
 * - дать LLM возможность сохранить важный промежуточный результат (план, разобранный JSON, черновик)
 *   под короткой меткой, связанной с текущим `sessionKey`;
 * - данные могут быть как JSON-структурой, так и обычным текстом.
 *
 * Как использовать из LLM:
 * - выберите стабильную и понятную метку (`label`), например `"requirements"`, `"parsed_config"`;
 * - передавайте `data` как JSON-строку, когда это возможно (массив/объект), иначе — как текст.
 */
final class IntermediateSaveTool extends ATool
{
    public function __construct(
        string $name = 'intermediate_save',
        string $description = 'Сохраняет промежуточный результат по метке в .store для текущего sessionKey. Пригодно для планов, разобранных структур, заметок.',
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
                name: 'data',
                type: PropertyType::STRING,
                description: 'Данные для сохранения. Рекомендуется JSON-строка ({"a":1} или ["x","y"]). Если JSON невалиден — сохраняется как обычный текст.',
                required: true,
            ),
        ];
    }

    /**
     * Сохраняет промежуточный результат.
     *
     * @param string $label Метка результата.
     * @param string $data  Данные (JSON-строка или текст).
     *
     * @return string JSON-результат.
     */
    public function __invoke(string $label, string $data): string
    {
        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $labelTrimmed = trim($label);

        if ($labelTrimmed === '') {
            return $this->resultJson(new IntermediateToolResultDto(
                action: 'save',
                success: false,
                message: 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $payload = $this->parseDataString($data);

        try {
            $item = IntermediateStorageHelper::save($sessionKey, $labelTrimmed, $payload);
        } catch (\Throwable $e) {
            return $this->resultJson(new IntermediateToolResultDto(
                action: 'save',
                success: false,
                message: 'Ошибка сохранения: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                label: $labelTrimmed,
            ));
        }

        return $this->resultJson(new IntermediateToolResultDto(
            action: 'save',
            success: true,
            message: 'Сохранено.',
            sessionKey: $sessionKey,
            label: $item->label,
            fileName: $item->fileName,
            savedAt: $item->savedAt,
            dataType: $item->dataType,
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

    /**
     * Сериализует результат в JSON.
     *
     * @param IntermediateToolResultDto $dto DTO результата.
     * @return string JSON.
     */
    private function resultJson(IntermediateToolResultDto $dto): string
    {
        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
