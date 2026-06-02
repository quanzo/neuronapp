<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\helpers;

/**
 * Безопасное имя базового каталога для пользователя в `.mind` по userId.
 *
 * Нужен для новой схемы `.mind/<userBasename>/...`.
 *
 * Пример:
 *
 * <code>
 * $base = MindStorageFilenameHelper::toBasename(42); // "user_42"
 * </code>
 */
final class MindStorageFilenameHelper
{
    /**
     * Максимальная длина «читаемой» части строкового id до усечения.
     */
    private const int MAX_LABEL_LENGTH = 64;

    /**
     * Строит безопасный basename директории пользователя.
     *
     * @param int|string $userId Идентификатор пользователя.
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
