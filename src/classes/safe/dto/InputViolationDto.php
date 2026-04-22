<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\dto;

/**
 * DTO нарушения безопасности входного сообщения.
 */
class InputViolationDto
{
    /**
     * Машиночитаемый код нарушения.
     */
    private string $code = '';

    /**
     * Человекочитаемое описание причины.
     */
    private string $reason = '';

    /**
     * Фрагмент текста, который вызвал срабатывание правила.
     */
    private string $matchedFragment = '';

    /**
     * Возвращает код нарушения.
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Устанавливает код нарушения.
     */
    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    /**
     * Возвращает описание причины.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Устанавливает описание причины.
     */
    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    /**
     * Возвращает сработавший фрагмент.
     */
    public function getMatchedFragment(): string
    {
        return $this->matchedFragment;
    }

    /**
     * Устанавливает сработавший фрагмент.
     */
    public function setMatchedFragment(string $matchedFragment): self
    {
        $this->matchedFragment = $matchedFragment;
        return $this;
    }

    /**
     * Возвращает DTO в виде ассоциативного массива.
     *
     * @return array{code:string,reason:string,matchedFragment:string}
     */
    public function toArray(): array
    {
        return [
            'code'            => $this->code,
            'reason'          => $this->reason,
            'matchedFragment' => $this->matchedFragment,
        ];
    }
}
