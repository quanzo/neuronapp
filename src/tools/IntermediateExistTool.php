<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use app\modules\neuron\classes\storage\IntermediateStorage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use app\modules\neuron\classes\config\ConfigurationApp;

use function json_encode;
use function trim;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент `IntermediateExistTool`: проверяет, существует ли промежуточный результат по метке.
 *
 * Назначение:
 * - дать LLM быстрый ответ, можно ли безопасно вызывать `IntermediateLoadTool` с указанной меткой;
 * - использовать в ветвлениях (если нет сохранённого результата — пересчитать или запросить у пользователя).
 */
final class IntermediateExistTool extends AIntermediateTool
{
    public function __construct(
        string $name = 'intermediate_exist',
        string $description = 'Проверяет, существует ли промежуточный результат по метке для текущего sessionKey.',
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
                name       : 'label',
                type       : PropertyType::STRING,
                description: 'Метка результата, существование которого нужно проверить.',
                required   : true,
            ),
        ];
    }

    /**
     * Проверяет наличие сохранённого результата по метке.
     *
     * @param string $label Метка результата.
     *
     * @return string JSON-результат.
     */
    public function __invoke(string $label): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();
        $labelTrimmed = trim($label);

        if ($labelTrimmed === '') {
            return $this->resultJson(new IntermediateToolResultDto(
                action    : 'exist',
                success   : false,
                message   : 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $exists = $storage->exists($sessionKey, $labelTrimmed);

        return $this->resultJson(new IntermediateToolResultDto(
            action    : 'exist',
            success   : true,
            message   : $exists ? 'Найдено.'                                          : 'Не найдено.',
            sessionKey: $sessionKey,
            label     : $labelTrimmed,
            fileName  : $exists ? $storage->resultFileName($sessionKey, $labelTrimmed) : null,
            exists    : $exists,
        ));
    }
}
