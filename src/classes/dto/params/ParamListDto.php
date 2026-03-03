<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\params;

/**
 * DTO‑обёртка над списком параметров, описанных в опции "params".
 *
 * Хранит набор объектов {@see ParamDto}, индексированных по имени параметра,
 * и предоставляет методы для поиска, получения полного списка и безопасного
 * построения этого набора из значения опции "params" (массива или JSON‑строки).
 * Используется для валидации плейсхолдеров и формирования схемы инструментов.
 */
final class ParamListDto
{
    /**
     * @var array<string, ParamDto>
     */
    private array $itemsByName;

    /**
     * Создаёт объект из заранее подготовленного набора параметров.
     *
     * @param array<string, ParamDto> $itemsByName Ассоциативный массив DTO‑параметров,
     *                                             где ключом является имя параметра.
     */
    private function __construct(array $itemsByName)
    {
        $this->itemsByName = $itemsByName;
    }

    /**
     * Возвращает полный список параметров без учёта их имён.
     *
     * @return list<ParamDto> Список всех параметров в произвольном порядке.
     */
    public function all(): array
    {
        return array_values($this->itemsByName);
    }

    /**
     * Проверяет наличие параметра с указанным именем.
     *
     * @param string $name Имя параметра (ключ в опции "params").
     *
     * @return bool true, если параметр с таким именем присутствует, иначе false.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->itemsByName);
    }

    /**
     * Возвращает DTO параметра по его имени.
     *
     * @param string $name Имя параметра (ключ в опции "params").
     *
     * @return ParamDto|null Объект параметра или null, если параметр с таким именем отсутствует.
     */
    public function get(string $name): ?ParamDto
    {
        return $this->itemsByName[$name] ?? null;
    }

    /**
     * Пытается построить список параметров из произвольного значения опции "params".
     *
     * Допускаются следующие входные форматы:
     *  - null — отсутствие опции, интерпретируется как пустой список параметров;
     *  - строка — JSON‑представление объекта с описаниями параметров;
     *  - массив — уже декодированный JSON‑объект.
     *
     * В случае ошибок формируется массив описаний ошибок, а список параметров может быть null.
     *
     * @param mixed $value Значение опции "params" (array|null|string), полученное из разбора настроек.
     *
     * @return array{
     *     0:?self,
     *     1:array<int,array{type:string,message:string,param?:string}>
     * }
     *     Пара: [список параметров или null, список найденных ошибок].
     */
    public static function tryFromOptionValue(mixed $value): array
    {
        if ($value === null) {
            return [new self([]), []];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [new self([]), []];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [null, [[
                    'type' => 'invalid_params_json',
                    'message' => 'Опция params должна быть JSON-объектом (object) с описаниями параметров.',
                ]]];
            }

            $value = $decoded;
        }

        if (!is_array($value)) {
            return [null, [[
                'type' => 'invalid_params_type',
                'message' => 'Опция params должна быть JSON-объектом (массивом) с описаниями параметров.',
            ]]];
        }

        $errors = [];
        $itemsByName = [];

        foreach ($value as $paramName => $def) {
            if (!is_string($paramName) || preg_match('/^[a-zA-Z]+$/', $paramName) !== 1) {
                $errors[] = [
                    'type' => 'invalid_param_name',
                    'param' => (string) $paramName,
                    'message' => 'Имя параметра должно содержать только латинские буквы [a-zA-Z].',
                ];
                continue;
            }

            if (is_string($def)) {
                $itemsByName[$paramName] = new ParamDto($paramName, $def);
                continue;
            }

            if (!is_array($def)) {
                $errors[] = [
                    'type' => 'invalid_param_definition_type',
                    'param' => $paramName,
                    'message' => 'Описание параметра должно быть строкой (тип) или объектом с ключом type.',
                ];
                continue;
            }

            $type = $def['type'] ?? 'string';
            if (!is_string($type) || $type === '') {
                $errors[] = [
                    'type' => 'invalid_param_type_value',
                    'param' => $paramName,
                    'message' => 'Поле type в описании параметра должно быть непустой строкой.',
                ];
                $type = 'string';
            }

            $description = $def['description'] ?? null;
            if ($description !== null && !is_string($description)) {
                $errors[] = [
                    'type' => 'invalid_param_description_value',
                    'param' => $paramName,
                    'message' => 'Поле description в описании параметра должно быть строкой.',
                ];
                $description = null;
            }

            $requiredRaw = $def['required'] ?? false;
            $required = is_bool($requiredRaw) ? $requiredRaw : (bool) $requiredRaw;

            $itemsByName[$paramName] = new ParamDto($paramName, $type, $description, $required);
        }

        return [new self($itemsByName), $errors];
    }
}

