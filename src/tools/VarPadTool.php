<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\dto\tools\VarToolResultDto;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function is_string;
use function ltrim;
use function str_ends_with;
use function str_starts_with;
use function trim;

/**
 * Инструмент `VarPadTool`: дополняет (append) строковые данные по указанной метке.
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
        string $description = 'Дополняет (append) строковые данные в переменной по ее имени, сохраняя переводы строк. Если переменная не существует - создаёт новую запись.',
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
                name: 'name',
                type: PropertyType::STRING,
                description: 'Имя переменной, которую нужно дополнить.',
                required: true,
            ),
            ToolProperty::make(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Краткое описание (1 строка) того, что хранится под этой меткой. Обязательна (может обновлять описание).',
                required: true,
            ),
            ToolProperty::make(
                name: 'data',
                type: PropertyType::STRING,
                description: 'Текст, который нужно добавить в конец. Данные должны быть строковыми.',
                required: true,
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

        if ($loaded !== null && $existing !== null && !is_string($existing)) {
            return $this->resultJson(new VarToolResultDto(
                action     : 'pad',
                success    : false,
                message    : 'Pad поддерживает только строковые данные. Текущие данные не строка.',
                sessionKey : $sessionKey,
                name      : $nameTrimmed,
                fileName   : $storage->resultFileName($sessionKey, $nameTrimmed),
                description: is_string($loaded['description'] ?? null) ? (string) $loaded['description'] : null,
                savedAt    : is_string($loaded['savedAt'] ?? null) ? (string) $loaded['savedAt'] : null,
                dataType   : is_string($loaded['dataType'] ?? null) ? (string) $loaded['dataType'] : null,
                exists     : true,
            ));
        }

        $existingText = is_string($existing) ? $existing : '';
        $appendText = $data;

        $merged = $this->mergeWithNewline($existingText, $appendText);

        try {
            $item = $storage->save($sessionKey, $nameTrimmed, $merged, $descTrimmed);
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

    private function mergeWithNewline(string $existing, string $append): string
    {
        if ($existing === '') {
            return $append;
        }
        if ($append === '') {
            return $existing;
        }

        $existingEndsNl = str_ends_with($existing, "\n");
        $appendStartsNl = str_starts_with($append, "\n");

        if (!$existingEndsNl && !$appendStartsNl) {
            return $existing . "\n" . $append;
        }

        if ($existingEndsNl && $appendStartsNl) {
            return $existing . ltrim($append, "\n");
        }

        return $existing . $append;
    }
}
