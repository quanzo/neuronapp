<?php

declare(strict_types=1);

namespace app\modules\neuron\interfaces;

/**
 * Интерфейс DTO событий ошибок.
 *
 * Позволяет единообразно распознавать любое DTO ошибочного события
 * через `$event instanceof IErrorEvent` вне зависимости от домена
 * (run, skill, tool, todo, orchestrator, agent message).
 *
 * Реализация полей вынесена в трейт {@see \app\modules\neuron\traits\HasErrorInfoTrait}.
 *
 * Пример использования:
 * ```php
 * if ($payload instanceof IErrorEvent) {
 *     $logger->error($payload->getErrorClass() . ': ' . $payload->getErrorMessage());
 * }
 * ```
 */
interface IErrorEvent
{
    /**
     * Возвращает FQCN класса исключения.
     */
    public function getErrorClass(): ?string;

    /**
     * Устанавливает FQCN класса исключения.
     *
     * @param ?string $errorClass Полное имя класса ошибки или null.
     */
    public function setErrorClass(?string $errorClass): static;

    /**
     * Возвращает текст сообщения об ошибке.
     */
    public function getErrorMessage(): ?string;

    /**
     * Устанавливает текст сообщения об ошибке.
     *
     * @param ?string $errorMessage Текст ошибки или null.
     */
    public function setErrorMessage(?string $errorMessage): static;
}
