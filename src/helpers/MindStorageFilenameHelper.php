<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Безопасное имя базового файла для долговременной памяти `.mind` по id пользователя.
 *
 * Целочисленные и строковые id приводятся к имени без слэшей и управляющих символов,
 * пригодному для использования в имени файла вместе с суффиксами `.md`, `.mind.idx`.
 *
 * Пример:
 *
 * <code>
 * $base = MindStorageFilenameHelper::toBasename(42); // "user_42"
 * $base = MindStorageFilenameHelper::toBasename('foo/bar'); // "user_foo_bar" или с хешем
 * </code>
 */
final class MindStorageFilenameHelper
{
    /**
     * Строит безопасный базовый сегмент имени файла для заданного user id.
     *
     * @param int|string $userId Идентификатор пользователя из {@see \app\modules\neuron\classes\config\ConfigurationApp::getUserId()}.
     *
     * @return string Базовое имя без расширения (например, `user_7`).
     */
    public static function toBasename(int|string $userId): string
    {
        // BC-wrapper: реализация переехала в `src/mind/helpers`.
        return \app\modules\neuron\mind\helpers\MindStorageFilenameHelper::toBasename($userId);
    }
}
