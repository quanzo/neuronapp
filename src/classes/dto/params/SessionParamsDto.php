<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\params;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO сессионных параметров для подстановки в плейсхолдеры.
 *
 * Инкапсулирует значения, которые приходят сверху (например, из CLI)
 * и могут использоваться в текстовых шаблонах как $date, $branch, $user.
 */
final class SessionParamsDto implements IArrayable
{
    private ?string $date = null;
    private ?string $branch = null;
    private ?string $user = null;

    /**
     * Устанавливает значение даты запуска сценария.
     *
     * @return $this
     */
    public function setDate(?string $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * Устанавливает имя git-ветки.
     *
     * @return $this
     */
    public function setBranch(?string $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    /**
     * Устанавливает имя пользователя.
     *
     * @return $this
     */
    public function setUser(?string $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?string
    {
        return $this->user;
    }

    /**
     * Возвращает массив значений для подстановки в параметры.
     *
     * Ключи совпадают с именами параметров (date, branch, user).
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->date !== null && $this->date !== '') {
            $result['date'] = $this->date;
        }

        if ($this->branch !== null && $this->branch !== '') {
            $result['branch'] = $this->branch;
        }

        if ($this->user !== null && $this->user !== '') {
            $result['user'] = $this->user;
        }

        return $result;
    }
}
