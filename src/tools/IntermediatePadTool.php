<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function is_string;
use function json_encode;
use function ltrim;
use function str_ends_with;
use function str_starts_with;
use function trim;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент `IntermediatePadTool`: дополняет (append) строковые данные по указанной метке.
 *
 * Отличие от {@see IntermediateSaveTool}:
 * - Save полностью перезаписывает данные.
 * - Pad добавляет новый текст в конец существующего текста, сохраняя переводы строк.
 *
 * Правила объединения строк:
 * - если существующий текст не пустой и не заканчивается `\\n`, а добавляемый текст не начинается с `\\n` — вставляется один `\\n`;
 * - если существующий текст заканчивается `\\n`, а добавляемый начинается с `\\n` — один ведущий `\\n` удаляется из добавляемого текста;
 * - если данных по label нет — создаётся новая запись.
 */
final class IntermediatePadTool extends AIntermediateTool
{
    public function __construct(
        string $name = 'intermediate_pad',
        string $description = 'Дополняет (append) строковые промежуточные данные по метке, сохраняя переводы строк. Если метки нет — создаёт новую запись.',
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
                description: 'Метка результата, который нужно дополнить.',
                required: true,
            ),
            ToolProperty::make(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Краткое описание (1 строка) того, что хранится под этой меткой. Обязательна (может обновлять описание).',
                required: true,
            ),
            ToolProperty::make(
                name: 'data',
                type: PropertyType::STRING,
                description: 'Текст, который нужно добавить в конец. Данные должны быть строковыми.',
                required: true,
            ),
        ];
    }

    /**
     * Дополняет текстовые данные по label или создаёт новую запись.
     *
     * @param string $label Метка результата.
     * @param string $description Краткое описание.
     * @param string $data Текст для добавления.
     *
     * @return string JSON-результат.
     */
    public function __invoke(string $label, string $description, string $data): string
    {
        $storage = $this->getStorage();
        $sessionKey = $this->getSessionKey();

        $labelTrimmed = trim($label);
        $descTrimmed = trim($description);

        if ($labelTrimmed === '') {
            return $this->resultJson(new IntermediateToolResultDto(
                action: 'pad',
                success: false,
                message: 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        if ($descTrimmed === '') {
            return $this->resultJson(new IntermediateToolResultDto(
                action: 'pad',
                success: false,
                message: 'description не может быть пустым.',
                sessionKey: $sessionKey,
                label: $labelTrimmed,
            ));
        }

        $loaded = $storage->load($sessionKey, $labelTrimmed);
        $existing = $loaded['data'] ?? null;

        if ($loaded !== null && $existing !== null && !is_string($existing)) {
            return $this->resultJson(new IntermediateToolResultDto(
                action: 'pad',
                success: false,
                message: 'Pad поддерживает только строковые данные. Текущие данные не строка.',
                sessionKey: $sessionKey,
                label: $labelTrimmed,
                fileName: $storage->resultFileName($sessionKey, $labelTrimmed),
                description: is_string($loaded['description'] ?? null) ? (string) $loaded['description'] : null,
                savedAt: is_string($loaded['savedAt'] ?? null) ? (string) $loaded['savedAt'] : null,
                dataType: is_string($loaded['dataType'] ?? null) ? (string) $loaded['dataType'] : null,
                exists: true,
            ));
        }

        $existingText = is_string($existing) ? $existing : '';
        $appendText = $data;

        $merged = $this->mergeWithNewline($existingText, $appendText);

        try {
            $item = $storage->save($sessionKey, $labelTrimmed, $merged, $descTrimmed);
        } catch (\Throwable $e) {
            return $this->resultJson(new IntermediateToolResultDto(
                action: 'pad',
                success: false,
                message: 'Ошибка сохранения: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                label: $labelTrimmed,
            ));
        }

        return $this->resultJson(new IntermediateToolResultDto(
            action: 'pad',
            success: true,
            message: $loaded === null ? 'Создано.' : 'Дополнено.',
            sessionKey: $sessionKey,
            label: $item->label,
            fileName: $item->fileName,
            description: $item->description,
            savedAt: $item->savedAt,
            dataType: $item->dataType,
            exists: true,
        ));
    }

    /**
     * Склеивает два фрагмента текста, сохраняя переводы строк.
     */
    private function mergeWithNewline(string $existing, string $append): string
    {
        if ($existing === '') {
            return $append;
        }
        if ($append === '') {
            return $existing;
        }

        $existingEndsNl = str_ends_with($existing, "\n");
        $appendStartsNl = str_starts_with($append, "\n");

        if (!$existingEndsNl && !$appendStartsNl) {
            return $existing . "\n" . $append;
        }

        if ($existingEndsNl && $appendStartsNl) {
            return $existing . ltrim($append, "\n");
        }

        return $existing . $append;
    }
}

