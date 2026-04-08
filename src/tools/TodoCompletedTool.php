<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use app\modules\neuron\helpers\TodoCompletedStatusHelper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function trim;

/**
 * Инструмент установки флага завершения `completed` в хранилище `.store`.
 *
 * Инструмент нужен для внешнего оркестратора: шаг `step` в todolist может явно
 * отметить успешное завершение обработки через этот tool.
 *
 * Поддерживаемые входные статусы:
 * - завершено: `done`, `1`, `true`, `исполнено`
 * - не завершено: `not_done`, `0`, `false`, `не исполнено`
 *
 * В хранилище записывается каноничное целочисленное значение `1|0` в name `completed`.
 */
final class TodoCompletedTool extends AVarTool
{
    public function __construct(
        string $name = 'todo_completed',
        string $description = 'Устанавливает промежуточный флаг completed (0/1) для текущей сессии.',
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
                name       : 'status',
                type       : PropertyType::STRING,
                description: 'Статус: ' . TodoCompletedStatusHelper::describeAllowedValues() . '.',
                required   : true,
            ),
            ToolProperty::make(
                name       : 'reason',
                type       : PropertyType::STRING,
                description: 'Краткое пояснение, почему выставлен этот статус.',
                required   : false,
            ),
        ];
    }

    /**
     * Устанавливает значение completed для текущего sessionKey.
     *
     * @return string JSON-ответ в формате VarToolResultDto.
     */
    public function __invoke(string $status, ?string $reason = null): string
    {
        $sessionKey = $this->getSessionKey();
        $normalized = TodoCompletedStatusHelper::normalize($status);

        if ($normalized === null) {
            return $this->resultJson(new VarToolResultDto(
                action    : 'todo_completed',
                success   : false,
                message   : 'Некорректный status. Используйте ' . TodoCompletedStatusHelper::describeAllowedValues() . '.',
                sessionKey: $sessionKey,
                name      : 'completed',
            ));
        }

        $desc = trim((string) ($reason ?? ''));
        if ($desc === '') {
            $desc = $normalized === 1 ? 'completed set to done' : 'completed set to not_done';
        }

        try {
            $item = $this->getStorage()->save($sessionKey, 'completed', $normalized, $desc);
        } catch (\Throwable $e) {
            return $this->resultJson(new VarToolResultDto(
                action    : 'todo_completed',
                success   : false,
                message   : 'Ошибка сохранения completed: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                name      : 'completed',
            ));
        }

        return $this->resultJson(new VarToolResultDto(
            action     : 'todo_completed',
            success    : true,
            message    : 'Флаг completed обновлён.',
            sessionKey : $sessionKey,
            name       : 'completed',
            fileName   : $item->fileName,
            description: $item->description,
            savedAt    : $item->savedAt,
            dataType   : $item->dataType,
            data       : $normalized,
            exists     : true,
        ));
    }
}
