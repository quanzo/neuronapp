<?php

namespace app\modules\neron\classes\status;
use app\modules\neron\interfaces\StatusInterface;

/**
 * Собирает строку состояния из массива объектов статусов.
 * Между статусами вставляет разделитель " | ".
 * Применяет цвета каждого статуса и сбрасывает их после каждого сегмента.
 */
class StatusBar
{
    /** @var StatusInterface[] */
    private array $statuses;

    /**
     * @param StatusInterface[] $statuses Начальный массив статусов
     */
    public function __construct(array $statuses = [])
    {
        $this->statuses = $statuses;
    }

    /**
     * Добавляет один статус в конец строки.
     *
     * @param StatusInterface $status
     * @return void
     */
    public function addStatus(StatusInterface $status): void
    {
        $this->statuses[] = $status;
    }

    /**
     * Заменяет все статусы новым массивом.
     *
     * @param StatusInterface[] $statuses
     * @return void
     */
    public function setStatuses(array $statuses): void
    {
        $this->statuses = $statuses;
    }

    /**
     * Формирует готовую к выводу строку состояния.
     *
     * @param int $width Максимальная ширина терминала (не используется для обрезки в данной реализации)
     * @return string Строка с ANSI-цветами
     */
    public function render(int $width): string
    {
        $segments = [];
        foreach ($this->statuses as $status) {
            $text = $status->getText();
            if ($text === '') {
                continue;
            }
            $color = $status->getColorCode();
            $segments[] = $color . $text . "\033[0m"; // сбрасываем цвет после каждого сегмента
        }

        return implode(' | ', $segments);
    }
}