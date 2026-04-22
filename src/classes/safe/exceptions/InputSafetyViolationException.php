<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\exceptions;

use app\modules\neuron\classes\safe\dto\InputViolationDto;
use RuntimeException;

/**
 * Исключение выбрасывается, когда входной текст признан небезопасным.
 */
class InputSafetyViolationException extends RuntimeException
{
    /**
     * @param InputViolationDto $violation Детали нарушения.
     */
    public function __construct(private readonly InputViolationDto $violation)
    {
        parent::__construct(
            sprintf(
                'Blocked unsafe LLM input (%s): %s',
                $violation->getCode(),
                $violation->getReason()
            )
        );
    }

    /**
     * Возвращает DTO с причиной блокировки.
     */
    public function getViolation(): InputViolationDto
    {
        return $this->violation;
    }
}
