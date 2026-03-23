<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\TodoGotoResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function json_encode;
use function trim;

use const JSON_UNESCAPED_UNICODE;

/**
 * Инструмент `TodoGotoTool`: запрашивает переход к указанному пункту TodoList.
 *
 * Инструмент не меняет индекс выполнения напрямую, а записывает запрос перехода
 * в `RunStateDto`. Сам переход применяет цикл `TodoList::execute()` после завершения
 * текущего шага.
 *
 * Пример вызова из LLM:
 * `{"tool":"todo_goto","args":{"point":2,"reason":"вернуться к подготовке данных"}}`
 */
final class TodoGotoTool extends ATool
{
    /**
     * Максимальное кол-во вызовов в сессии одного агента этого инструмента
     *
     * @var integer|null
     */
    protected ?int $maxRuns = 50;

    public function __construct(
        string $name = 'todo_goto',
        string $description = 'Запрашивает переход к пункту списка по номеру (1-based). '
        . 'Переход применится после завершения текущего шага.',
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
                name       : 'point',
                type       : PropertyType::INTEGER,
                description: 'Номер целевого пункта списка (1-based). Пример: 1 = первый пункт.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'reason',
                type       : PropertyType::STRING,
                description: 'Краткая причина перехода (необязательно).',
                required   : false,
            ),
        ];
    }

    /**
     * Записывает запрос перехода в run-state.
     *
     * @param int         $point Номер целевого пункта (1-based).
     * @param string|null $reason Причина перехода.
     *
     * @return string JSON-ответ для LLM.
     */
    public function __invoke(int $point, ?string $reason = null): string
    {
        $agentCfg = $this->getAgentCfg();
        if ($agentCfg === null) {
            return $this->resultJson(new TodoGotoResultDto(
                success: false,
                message: 'Инструмент todo_goto не привязан к конфигурации агента.',
                toPoint: $point,
                reason : $reason,
            ));
        }

        if ($point < 1) {
            return $this->resultJson(new TodoGotoResultDto(
                success: false,
                message: 'point должен быть >= 1.',
                toPoint: $point,
                reason : $reason,
            ));
        }

        $runStateDto = $agentCfg->getExistRunStateDto();
        if ($runStateDto === null) {
            return $this->resultJson(new TodoGotoResultDto(
                success: false,
                message: 'Переход недоступен: активный TodoList run-state не найден.',
                toPoint: $point,
                reason : $reason,
            ));
        }

        $targetIndex = $point - 1;
        $fromPoint = $runStateDto->getLastCompletedTodoIndex() + 1;
        $runStateDto
            ->setGotoRequestedTodoIndex($targetIndex)
            ->write();

        return $this->resultJson(new TodoGotoResultDto(
            success  : true,
            message  : 'Запрос перехода сохранён.',
            fromPoint: $fromPoint,
            toPoint  : $point,
            reason   : $this->normalizeReason($reason),
        ));
    }

    /**
     * Нормализует необязательную причину перехода.
     *
     * @param string|null $reason Исходная причина.
     *
     * @return string|null
     */
    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Сериализует результат инструмента в JSON.
     *
     * @param TodoGotoResultDto $dto DTO результата.
     *
     * @return string
     */
    private function resultJson(TodoGotoResultDto $dto): string
    {
        return json_encode($dto->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
