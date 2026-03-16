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
 * Инструмент `IntermediateDeleteTool`: удаляет сохранённый промежуточный результат по метке.
 *
 * Назначение:
 * - позволить LLM очищать больше не нужные промежуточные результаты для текущего `sessionKey`;
 * - поддерживать аккуратное хранилище `.store` в долгих сессиях.
 */
final class IntermediateDeleteTool extends AIntermediateTool
{
    public function __construct(
        string $name = 'intermediate_delete',
        string $description = 'Удаляет сохранённый промежуточный результат по метке для текущего sessionKey (если он существует).',
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
                description: 'Метка результата, который нужно удалить.',
                required: true,
            ),
        ];
    }

    /**
     * Удаляет сохранённый результат по метке.
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
                action    : 'delete',
                success   : false,
                message   : 'label не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $existedBefore = $storage->exists($sessionKey, $labelTrimmed);

        try {
            $storage->delete($sessionKey, $labelTrimmed);
        } catch (\Throwable $e) {
            return $this->resultJson(new IntermediateToolResultDto(
                action    : 'delete',
                success   : false,
                message   : 'Ошибка удаления: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                label     : $labelTrimmed,
            ));
        }

        return $this->resultJson(new IntermediateToolResultDto(
            action    : 'delete',
            success   : true,
            message   : $existedBefore ? 'Удалено.': 'Нечего удалять (запись отсутствовала).',
            sessionKey: $sessionKey,
            label     : $labelTrimmed,
            exists    : false,
        ));
    }
}
