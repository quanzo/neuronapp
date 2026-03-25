<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\StoreToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function trim;

/**
 * Инструмент `StoreExistTool`: проверяет, существует ли результат по метке.
 *
 * Назначение:
 * - дать LLM быстрый ответ, можно ли безопасно вызывать `StoreLoadTool` с указанной меткой;
 * - использовать в ветвлениях (если нет сохранённого результата — пересчитать или запросить у пользователя).
 */
final class StoreExistTool extends AStoreTool
{
    public function __construct(
        string $name = 'store_exist',
        string $description = 'Проверяет, существует ли результат по метке для текущего sessionKey.',
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
            return $this->resultJson(new StoreToolResultDto(
                action    : 'exist',
                success   : false,
                message   : 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $exists = $storage->exists($sessionKey, $labelTrimmed);

        return $this->resultJson(new StoreToolResultDto(
            action    : 'exist',
            success   : true,
            message   : $exists ? 'Найдено.' : 'Не найдено.',
            sessionKey: $sessionKey,
            label     : $labelTrimmed,
            fileName  : $exists ? $storage->resultFileName($sessionKey, $labelTrimmed) : null,
            exists    : $exists,
        ));
    }
}
