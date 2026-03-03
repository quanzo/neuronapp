<?php

declare(strict_types=1);

namespace Tests\Status;

use app\modules\neuron\classes\status\HistoryCountStatus;
use app\modules\neuron\interfaces\StatusInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see HistoryCountStatus}.
 *
 * HistoryCountStatus — компонент строки состояния, отображающий количество
 * сообщений в истории чата. Формат: «Сообщений: N». Цвет — синий.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\status\HistoryCountStatus}
 */
class HistoryCountStatusTest extends TestCase
{
    /**
     * Класс реализует StatusInterface.
     */
    public function testImplementsInterface(): void
    {
        $status = new HistoryCountStatus(0);
        $this->assertInstanceOf(StatusInterface::class, $status);
    }

    /**
     * Нулевое количество сообщений — «Сообщений: 0».
     */
    public function testGetTextZero(): void
    {
        $status = new HistoryCountStatus(0);
        $this->assertSame('Сообщений: 0', $status->getText());
    }

    /**
     * Положительное число — корректное форматирование.
     */
    public function testGetTextPositive(): void
    {
        $status = new HistoryCountStatus(42);
        $this->assertSame('Сообщений: 42', $status->getText());
    }

    /**
     * Отрицательное число — тоже отображается (граничный случай).
     */
    public function testGetTextNegative(): void
    {
        $status = new HistoryCountStatus(-1);
        $this->assertSame('Сообщений: -1', $status->getText());
    }

    /**
     * ANSI-код цвета — синий (\033[94m).
     */
    public function testGetColorCodeBlue(): void
    {
        $status = new HistoryCountStatus(0);
        $this->assertSame("\033[94m", $status->getColorCode());
    }
}
