<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\dto;

/**
 * DTO нарушения безопасности выходного сообщения LLM.
 */
class OutputViolationDto
{
    /**
     * Машиночитаемый код нарушения.
     */
    private string $code = '';

    /**
     * Описание причины срабатывания правила.
     */
    private string $reason = '';

    /**
     * Сработавший фрагмент текста.
     */
    private string $matchedFragment = '';

    /**
     * Строка, на которую заменяется опасный фрагмент.
     */
    private string $replacement = '';

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
     * Возвращает описание нарушения.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Устанавливает описание нарушения.
     */
    public function setReason(string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    /**
     * Возвращает фрагмент, вызвавший нарушение.
     */
    public function getMatchedFragment(): string
    {
        return $this->matchedFragment;
    }

    /**
     * Устанавливает фрагмент, вызвавший нарушение.
     */
    public function setMatchedFragment(string $matchedFragment): self
    {
        $this->matchedFragment = $matchedFragment;
        return $this;
    }

    /**
     * Возвращает replacement для редактирования ответа.
     */
    public function getReplacement(): string
    {
        return $this->replacement;
    }

    /**
     * Устанавливает replacement для редактирования ответа.
     */
    public function setReplacement(string $replacement): self
    {
        $this->replacement = $replacement;
        return $this;
    }

    /**
     * Возвращает DTO в виде массива.
     *
     * @return array{code:string,reason:string,matchedFragment:string,replacement:string}
     */
    public function toArray(): array
    {
        return [
            'code'            => $this->code,
            'reason'          => $this->reason,
            'matchedFragment' => $this->matchedFragment,
            'replacement'     => $this->replacement,
        ];
    }
}
