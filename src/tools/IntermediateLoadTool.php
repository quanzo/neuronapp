<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use app\modules\neuron\classes\storage\IntermediateStorage;
use app\modules\neuron\classes\config\ConfigurationApp;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function json_encode;
use function is_string;
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
        ];
    }

    /**
     * Загружает промежуточный результат по метке.
     *
     * @param string $label Метка результата.
     *
     * @return string JSON-результат (включает data при успехе).
     */
    public function __invoke(string $label): string
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
        $savedAt = is_string($loaded['savedAt'] ?? null) ? (string) $loaded['savedAt'] : null;
        $dataType = is_string($loaded['dataType'] ?? null) ? (string) $loaded['dataType'] : null;

        return $this->resultJson(new IntermediateToolResultDto(
            action    : 'load',
            success   : true,
            message   : 'Загружено.',
            sessionKey: $sessionKey,
            label     : $labelTrimmed,
            fileName  : $storage->resultFileName($sessionKey, $labelTrimmed),
            savedAt   : $savedAt,
            dataType  : $dataType,
            data      : $data,
            exists    : true,
        ));
    }
}
