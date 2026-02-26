<?php

namespace app\modules\neron\classes\status;
use app\modules\neron\interfaces\StatusInterface;

/**
 * Статус, отображающий количество сообщений в истории.
 */
class HistoryCountStatus implements StatusInterface
{
    private int $count;

    /**
     * @param int $count Количество сообщений
     */
    public function __construct(int $count)
    {
        $this->count = $count;
    }

    /**
     * @inheritDoc
     */
    public function getText(): string
    {
        return "Сообщений: {$this->count}";
    }

    /**
     * @inheritDoc
     */
    public function getColorCode(): string
    {
        return "\033[94m"; // синий
    }
}