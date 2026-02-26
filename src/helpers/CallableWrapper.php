<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\exceptions\InvalidTypeException;

/**
 * Расширение типа callable в виде массива для вызова методов объектов с параметрами
 */
class CallableWrapper {
    /**
     * Callable в виде массива с параметрами
     * 
     * Вот такой:
     * [CallableWrapper::class, 'createObject', 'class' => Ollama::class, 'url' => 'http://localhost:11434/api', 'model' => 'codegemma:7b']
     * массив будет считаться callable и будет вызван метод createObject, который создаст объект из класса с параметрами конструктора класса url и model
     *
     * @param array|callable $call
     * @return mixed
     * @throws InvalidTypeException
     */
    public static function call(array|callable $call) {
        if (static::isCallable($call)) {
            if (is_array($call)) {
                // ждем минимум два значения - первое класс, второе метод
                $call0 = array_splice($call, 0, 2);
                if ($call) {
                    return $call0(...$call);
                } else {
                    return $call0();
                }
            } else {
                return $call();
            }
        }
        throw new InvalidTypeException('Argument $call is not callable');
    }

    /**
     * Создать объект из класса.
     *
     * @param string $class - имя класса
     * @param array $params - здесь параметры надо передавать ассоциативный массив. ключи должны соответсвовать параметрам конструктора класса $class. Лишних ключей быть не должно.
     * @return mixed
     */
    public static function createObject(string $class, ...$params) {
        return new $class(...$params);
    }

    /**
     * Проверяет, что переданный аргумент соответсвует типу callable, в т.ч. и расширенному типу callable в массиве
     *
     * @param mixed $call
     * @return boolean
     */
    public static function isCallable($call): bool {
        if (is_callable($call)) {
            return true;
        } elseif (is_array($call) && sizeof($call) >= 2) {
            // ждем минимум два значения - первое класс, второе метод
            $call0 = array_splice($call, 0, 2);
            return is_callable($call0);
        }
        return false;
    }
}
