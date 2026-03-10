<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

use app\modules\neuron\enums\AttachmentTypeEnum;

/**
 * Интерфейс вложения в виде файла
 */
interface IAttachmentFile
{
    /**
     * Возвращает тип вложения
     *
     * @return AttachmentTypeEnum Тип вложения-файла
     */
    public function getAttachmentType(): AttachmentTypeEnum;

    /**
     * Возвращает путь к файлу изображения или идентификатор ресурса.
     *
     * @return string Путь или идентификатор файла изображения.
     */
    public function getPath(): string;
}
