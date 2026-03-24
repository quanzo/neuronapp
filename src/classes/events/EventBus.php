<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\events;

/**
 * Шина событий приложения.
 *
 * Реестр хранит подписки по имени события и контекстному ключу.
 * Контекстный ключ может быть:
 * - `*` (глобальная подписка для события),
 * - конкретное имя класса (`class-string`).
 *
 * Обработчик получает payload события и может вернуть `false`,
 * чтобы остановить дальнейшую обработку цепочки.
 *
 * Пример использования:
 * ```php
 * EventBus::on('task.created', static function (mixed $payload): ?bool {
 *     return null;
 * });
 *
 * EventBus::trigger('task.created', '*', ['id' => 100]);
 * ```
 */
class EventBus
{
    protected const GLOBAL_EVENT_KEY = '*';
    protected const CLASS_EVENT_KEY_PREFIX = 'class:';
    protected const OBJECT_EVENT_KEY_PREFIX = 'object:';

    /**
     * Внутреннее хранилище подписок:
     * [eventName => [eventKey => list<callable>]].
     *
     * @var array<string, array<string, array<int, callable>>>
     */
    protected static array $events = [];

    /**
     * Подписать обработчик на событие.
     *
     * Обработчик будет вызван при `trigger()` с тем же именем события
     * и подходящим контекстным ключом.
     *
     * @param string $eventName Название события.
     * @param callable $handlerFunc Обработчик события.
     * @param object|string $_class Контекст события (`*`, class-string или объект).
     * @return void
     */
    public static function on(
        string $eventName,
        callable $handlerFunc,
        object|string $_class = self::GLOBAL_EVENT_KEY
    ): void {
        $eventKey = static::normalizeEventKey($_class);

        if (!isset(static::$events[$eventName])) {
            static::$events[$eventName] = [];
        }
        if (!isset(static::$events[$eventName][$eventKey])) {
            static::$events[$eventName][$eventKey] = [];
        }

        if (in_array($handlerFunc, static::$events[$eventName][$eventKey], true)) {
            return;
        }

        static::$events[$eventName][$eventKey][] = $handlerFunc;
    }

    /**
     * Отписать обработчик от события.
     *
     * Удаляет все совпадения обработчика в рамках события и контекста.
     * После удаления выполняется очистка пустых веток хранилища.
     *
     * @param string $eventName Название события.
     * @param callable $handlerFunc Обработчик события.
     * @param object|string $_class Контекст события (`*`, class-string или объект).
     * @return void
     */
    public static function off(
        string $eventName,
        callable $handlerFunc,
        object|string $_class = self::GLOBAL_EVENT_KEY
    ): void {
        $eventKey = static::normalizeEventKey($_class);

        if (!isset(static::$events[$eventName][$eventKey])) {
            return;
        }

        $found = array_keys(static::$events[$eventName][$eventKey], $handlerFunc, true);
        if ($found) {
            foreach ($found as $handlerIndex) {
                unset(static::$events[$eventName][$eventKey][$handlerIndex]);
            }
        }

        if (empty(static::$events[$eventName][$eventKey])) {
            unset(static::$events[$eventName][$eventKey]);
        }
        if (empty(static::$events[$eventName])) {
            unset(static::$events[$eventName]);
        }
    }

    /**
     * Вызвать событие.
     *
     * Если обработчик возвращает `false`, выполнение цепочки
     * обработчиков для найденного контекстного ключа прекращается.
     *
     * @param string $eventName Название события.
     * @param object|string $_class Контекст события (`*`, class-string или объект).
     * @param mixed $eventData Payload, передаваемый обработчикам.
     * @return void
     */
    public static function trigger(
        string $eventName,
        object|string $_class = self::GLOBAL_EVENT_KEY,
        mixed $eventData = null
    ): void {
        if (empty(static::$events[$eventName])) {
            return;
        }

        $eventKeys = static::resolveTriggerEventKeys($_class);
        foreach ($eventKeys as $eventKey) {
            if (!isset(static::$events[$eventName][$eventKey])) {
                continue;
            }

            foreach (static::$events[$eventName][$eventKey] as $callback) {
                $result = $callback($eventData);
                if ($result !== null && $result == false) {
                    return;
                }
            }
        }
    }

    /**
     * Очистить подписки событий.
     *
     * При вызове без аргументов удаляет все подписки.
     * При передаче имени события очищает только его.
     *
     * @param string|null $eventName Название события для точечной очистки.
     * @return void
     */
    public static function clear(?string $eventName = null): void
    {
        if ($eventName === null) {
            static::$events = [];
            return;
        }

        unset(static::$events[$eventName]);
    }

    /**
     * Нормализовать входной контекст в ключ хранилища.
     *
     * @param object|string $_class Исходный контекст события.
     * Если передан объект, то ключ формируется только для конкретного объекта.
     *
     * @return string Нормализованный ключ (`*`, `class:<FQCN>`, `object:<id>`).
     */
    protected static function normalizeEventKey(object|string $_class): string
    {
        if (is_object($_class)) {
            return static::OBJECT_EVENT_KEY_PREFIX . spl_object_id($_class);
        }

        if ($_class === '' || $_class === static::GLOBAL_EVENT_KEY) {
            return static::GLOBAL_EVENT_KEY;
        }

        return static::CLASS_EVENT_KEY_PREFIX . ltrim($_class, '\\');
    }

    /**
     * Разрешить ключи для вызова обработчиков в trigger().
     *
     * Если передан объект:
     * - сначала вызываются обработчики конкретного объекта;
     * - затем его класса и родительских классов;
     * - затем глобальные обработчики (`*`).
     *
     * Если передано имя класса:
     * - вызываются обработчики точного класса;
     * - затем глобальные обработчики (`*`).
     *
     * @param object|string $_class Контекст события.
     * @return array<int, string> Ключи хранилища в порядке вызова.
     */
    protected static function resolveTriggerEventKeys(object|string $_class): array
    {
        if (is_object($_class)) {
            $keys = [static::normalizeEventKey($_class)];
            $keys[] = static::CLASS_EVENT_KEY_PREFIX . get_class($_class);

            foreach (array_values(class_parents($_class)) as $parentClass) {
                $keys[] = static::CLASS_EVENT_KEY_PREFIX . $parentClass;
            }

            $keys[] = static::GLOBAL_EVENT_KEY;
            return array_values(array_unique($keys));
        }

        if ($_class === '' || $_class === static::GLOBAL_EVENT_KEY) {
            return [static::GLOBAL_EVENT_KEY];
        }

        return [
            static::CLASS_EVENT_KEY_PREFIX . ltrim($_class, '\\'),
            static::GLOBAL_EVENT_KEY,
        ];
    }
}
