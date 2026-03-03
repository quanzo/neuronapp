<?php

namespace app\modules\neuron\classes\status;
use app\modules\neuron\interfaces\StatusInterface;

/**
 * Статус, отображающий текущий режим (фокус).
 */
class ModeStatus implements StatusInterface
{
    private string $mode;

    /**
     * @param string $mode Название режима (например, "ВВОД" или "ПРОСМОТР")
     */
    public function __construct(string $mode)
    {
        $this->mode = $mode;
    }

    /**
     * @inheritDoc
     */
    public function getText(): string
    {
        return $this->mode;
    }

    /**
     * @inheritDoc
     */
    public function getColorCode(): string
    {
        return "\033[93m"; // жёлтый
    }
}