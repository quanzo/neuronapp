<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\dto;

/**
 * DTO оценки размера среза записей долговременной памяти.
 *
 * Содержит суммарное число символов (UTF-8) и оценку токенов.
 *
 * Пример:
 *
 * <code>
 * $dto = (new MindSliceEstimateDto())
 *     ->setCharacterCount(120)
 *     ->setTokenCount(35);
 * </code>
 */
final class MindSliceEstimateDto
{
    private int $characterCount = 0;
    private int $tokenCount = 0;

    /**
     * Возвращает суммарное число символов в срезе (UTF-8).
     */
    public function getCharacterCount(): int
    {
        return $this->characterCount;
    }

    /**
     * Устанавливает суммарное число символов.
     *
     * @param int $characterCount Неотрицательное число символов Unicode.
     */
    public function setCharacterCount(int $characterCount): self
    {
        $this->characterCount = max(0, $characterCount);
        return $this;
    }

    /**
     * Возвращает оценку числа токенов.
     */
    public function getTokenCount(): int
    {
        return $this->tokenCount;
    }

    /**
     * Устанавливает оценку числа токенов.
     *
     * @param int $tokenCount Неотрицательная оценка токенов.
     */
    public function setTokenCount(int $tokenCount): self
    {
        $this->tokenCount = max(0, $tokenCount);
        return $this;
    }
}
