<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\IntermediateToolResultDto;
use app\modules\neuron\classes\storage\IntermediateStorage;
use app\modules\neuron\classes\config\ConfigurationApp;

use function count;
use function json_encode;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент `IntermediateListTool`: возвращает список промежуточных результатов для текущего `sessionKey`.
 *
 * Назначение:
 * - дать LLM обзор того, какие метки уже сохранены в текущей сессии;
 * - использовать этот список, чтобы выбирать подходящий `label` для дальнейших load/exist.
 */
final class IntermediateListTool extends AIntermediateTool
{
    public function __construct(
        string $name = 'intermediate_list',
        string $description = 'Список всех промежуточных результатов в .store для текущего sessionKey (метки и метаданные).',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * У этого инструмента нет входных параметров.
     *
     * @return array
     */
    protected function properties(): array
    {
        return [];
    }

    /**
     * Возвращает список сохранённых промежуточных результатов.
     *
     * @return string JSON-результат со списком items.
     */
    public function __invoke(): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();

        $items = $storage->list($sessionKey);

        return $this->resultJson(new IntermediateToolResultDto(
            action    : 'list',
            success   : true,
            message   : 'OK',
            sessionKey: $sessionKey,
            items     : $items,
            count     : count($items),
        ));
    }
}
