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
     * Максимальная длина «читаемой» части строкового id до усечения/хеширования.
     */
    private const MAX_LABEL_LENGTH = 64;

    /**
     * Строит безопасный базовый сегмент имени файла для заданного user id.
     *
     * @param int|string $userId Идентификатор пользователя из {@see \app\modules\neuron\classes\config\ConfigurationApp::getUserId()}.
     *
     * @return string Базовое имя без расширения (например, `user_7`).
     */
    public static function toBasename(int|string $userId): string
    {
        if (\is_int($userId)) {
            return 'user_' . (string) $userId;
        }

        $label = (string) $userId;
        $label = str_replace(["\0", '/', '\\'], '_', $label);
        $label = preg_replace('/[^\p{L}\p{N}_\-.]+/u', '_', $label) ?? '';
        $label = trim($label, '._-');
        if ($label === '') {
            $label = 'empty';
        }

        if (mb_strlen($label, 'UTF-8') > self::MAX_LABEL_LENGTH) {
            $label = mb_substr($label, 0, self::MAX_LABEL_LENGTH, 'UTF-8');
        }

        return 'user_' . $label;
    }
}
