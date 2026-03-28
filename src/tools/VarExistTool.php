<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function trim;

/**
 * Инструмент `VarExistTool`: проверяет, существует ли результат по метке.
 */
final class VarExistTool extends AVarTool
{
    public function __construct(
        string $name = 'var_exist',
        string $description = 'Проверяет наличие переменной с таким именем.',
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
                description: 'Имя переменно, существование которой нужно проверить.',
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
                action    : 'exist',
                success   : false,
                message   : 'Имя переменной не не может быть пустым.',
                sessionKey: $sessionKey,
            ));
        }

        $exists = $storage->exists($sessionKey, $nameTrimmed);

        return $this->resultJson(new VarToolResultDto(
            action    : 'exist',
            success   : true,
            message   : $exists ? 'Найдено.' : 'Не найдено.',
            sessionKey: $sessionKey,
            name      : $nameTrimmed,
            fileName  : $exists ? $storage->resultFileName($sessionKey, $nameTrimmed) : null,
            exists    : $exists,
        ));
    }
}
