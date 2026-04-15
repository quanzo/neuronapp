<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use app\modules\neuron\enums\VarDataTypeEnum;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\helpers\VarMergeHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function is_string;
use function json_last_error;
use function trim;

use const JSON_ERROR_NONE;

/**
 * Инструмент `VarPadTool`: дополняет (pad/append) данные по указанной метке.
 *
 * Поддерживает типобезопасное дополнение (typed pad):
 * - string: склеивание с сохранением переводов строк
 * - array: дополнение list/map по правилам VarMergeHelper
 * - number: арифметическое сложение
 * - null: трактуется как “пусто” (результат становится append)
 *
 * Входной параметр `data` остаётся строкой: если это валидный JSON — будет
 * распарсен как значение (array/number/boolean/null/string), иначе считается
 * обычным текстом.
 */
final class VarPadTool extends AVarTool
{
    /**
     * Максимальное кол-во вызовов в сессии одного агента этого инструмента.
     *
     * @var int|null
     */
    protected ?int $maxRuns = 50;

    public function __construct(
        string $name = 'var_pad',
        string $description = 'Дополняет (append) данные по имени переменной. data — строка: если это валидный JSON, используется decoded значение. Поддерживает string/array/number/null; array: list+list=concat, map+map=merge(overwrite). number: existing+append. boolean/object не поддерживает (используйте var_set).',
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
                description: 'Имя переменной, которую нужно дополнить.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'description',
                type       : PropertyType::STRING,
                description: 'Краткое описание (1 строка) того, что хранится под этой меткой. Обязательна (может обновлять описание).',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'data',
                type       : PropertyType::STRING,
                description: 'Строка для дополнения. Если это валидный JSON — будет использовано decoded значение (JSON-число/массив/объект/null/строка). Иначе добавится как обычный текст.',
                required   : true,
            ),
        ];
    }

    public function __invoke(string $name, string $description, string $data): string
    {
        $storage = $this->getStorage();
        $sessionKey = $this->getSessionKey();

        $nameTrimmed = trim($name);
        $descTrimmed = trim($description);

        if ($nameTrimmed === '') {
            return $this->resultJson(new VarToolResultDto(
                action: 'pad',
                success: false,
                message: 'name не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        if ($descTrimmed === '') {
            return $this->resultJson(new VarToolResultDto(
                action    : 'pad',
                success   : false,
                message   : 'description не может быть пустым.',
                sessionKey: $sessionKey,
                name     : $nameTrimmed,
            ));
        }

        $loaded = $storage->load($sessionKey, $nameTrimmed);
        $existing = $loaded['data'] ?? null;
        $append = $this->parseDataString($data);

        $merge = VarMergeHelper::mergeForPad($existing, $append);
        if (!$merge->success) {
            $descriptionOut = is_string($loaded['description'] ?? null) ? (string) $loaded['description'] : null;
            $savedAtOut     = is_string($loaded['savedAt'] ?? null) ? (string) $loaded['savedAt'] : null;
            $dataTypeOut    = is_string($loaded['dataType'] ?? null) ? (string) $loaded['dataType'] : null;

            return $this->resultJson(new VarToolResultDto(
                action     : 'pad',
                success    : false,
                message    : $merge->message,
                sessionKey : $sessionKey,
                name       : $nameTrimmed,
                fileName   : $loaded === null ? null : $storage->resultFileName($sessionKey, $nameTrimmed),
                description: $descriptionOut,
                savedAt    : $savedAtOut,
                dataType   : $dataTypeOut ?? $merge->existingType->value,
                exists     : $loaded !== null,
            ));
        }

        try {
            $item = $storage->save($sessionKey, $nameTrimmed, $merge->merged, $descTrimmed);
        } catch (\Throwable $e) {
            return $this->resultJson(new VarToolResultDto(
                action    : 'pad',
                success   : false,
                message   : 'Ошибка сохранения: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                name     : $nameTrimmed,
            ));
        }

        return $this->resultJson(new VarToolResultDto(
            action     : 'pad',
            success    : true,
            message    : $loaded === null ? 'Создано.' : 'Дополнено.',
            sessionKey : $sessionKey,
            name      : $item->name,
            fileName   : $item->fileName,
            description: $item->description,
            savedAt    : $item->savedAt,
            dataType   : $item->dataType,
            exists     : true,
        ));
    }

    /**
     * Парсит входной параметр `data` (строка) в typed-значение.
     *
     * Правило совпадает с `VarSetTool`: если строка — валидный JSON, сохраняем decoded значение,
     * иначе сохраняем как обычный текст.
     *
     * @param string $raw Входные данные инструмента.
     * @return mixed Decoded JSON или исходная строка.
     */
    private function parseDataString(string $raw): mixed
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $decoded = JsonHelper::decodeAssociative($trimmed);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw;
    }
}
