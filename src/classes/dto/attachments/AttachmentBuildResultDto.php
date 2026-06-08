<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\attachments;

/**
 * DTO результата построения вложений из путей файлов.
 *
 * При ошибке {@see getErrorMessage()} непустой, список вложений пуст.
 *
 * Пример:
 *
 * <code>
 * $result = AttachmentHelper::buildAttachmentsFromPaths(['/path/to/file.txt']);
 * if ($result->isError()) {
 *     return OutputDto::fromError($result->getErrorMessage());
 * }
 * </code>
 */
final class AttachmentBuildResultDto
{
    /**
     * @param list<AttachmentDto> $attachments  Собранные вложения.
     * @param string              $errorMessage Сообщение об ошибке; пустая строка — успех.
     */
    public function __construct(
        private array $attachments = [],
        private string $errorMessage = '',
    ) {
    }

    /**
     * Признак ошибки построения вложений.
     */
    public function isError(): bool
    {
        return $this->errorMessage !== '';
    }

    /**
     * Сообщение об ошибке.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Список собранных вложений.
     *
     * @return list<AttachmentDto>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }
}
