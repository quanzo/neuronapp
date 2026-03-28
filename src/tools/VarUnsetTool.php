<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function trim;

/**
 * Инструмент `VarUnsetTool`: удалить переменную (результат) по метке.
 */
final class VarUnsetTool extends AVarTool
{
    public function __construct(
        string $name = 'var_unset',
        string $description = 'Удаляет переменную по её имени.',
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
                description: 'Имя переменной, которая будет удалена.',
                required   : true,
            ),
        ];
    }

    public function __invoke(string $name): string
    {
        $storage      = $this->getStorage();
        $sessionKey   = $this->getSessionKey();
        $nameTrimmed = trim($name);

        if ($nameTrimmed === '') {
            return $this->resultJson(new VarToolResultDto(
                action    : 'unset',
                success   : false,
                message   : 'name не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $existedBefore = $storage->exists($sessionKey, $nameTrimmed);

        try {
            $storage->delete($sessionKey, $nameTrimmed);
        } catch (\Throwable $e) {
            return $this->resultJson(new VarToolResultDto(
                action    : 'unset',
                success   : false,
                message   : 'Ошибка удаления: ' . $e->getMessage(),
                sessionKey: $sessionKey,
                name     : $nameTrimmed,
            ));
        }

        return $this->resultJson(new VarToolResultDto(
            action    : 'unset',
            success   : true,
            message   : $existedBefore ? 'Удалено.' : 'Нечего удалять (запись отсутствовала).',
            sessionKey: $sessionKey,
            name     : $nameTrimmed,
            exists    : false,
        ));
    }
}
