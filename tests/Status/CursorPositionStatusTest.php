<?php

declare(strict_types=1);

namespace Tests\Status;

use app\modules\neuron\classes\status\CursorPositionStatus;
use app\modules\neuron\interfaces\StatusInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see CursorPositionStatus}.
 *
 * CursorPositionStatus — компонент строки состояния, отображающий текущую
 * позицию курсора в поле ввода. Принимает 0-базовые индексы строки и колонки,
 * а при отображении прибавляет 1 (для удобства пользователя).
 * Формат: «Стр N, кол M». Цвет — зелёный.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\status\CursorPositionStatus}
 */
class CursorPositionStatusTest extends TestCase
{
    /**
     * Класс реализует StatusInterface.
     */
    public function testImplementsInterface(): void
    {
        $status = new CursorPositionStatus(0, 0);
        $this->assertInstanceOf(StatusInterface::class, $status);
    }

    /**
     * (0, 0) → «Стр 1, кол 1» (отображение 1-базовое).
     */
    public function testGetTextZeroBased(): void
    {
        $status = new CursorPositionStatus(0, 0);
        $this->assertSame('Стр 1, кол 1', $status->getText());
    }

    /**
     * (4, 9) → «Стр 5, кол 10».
     */
    public function testGetTextNonZero(): void
    {
        $status = new CursorPositionStatus(4, 9);
        $this->assertSame('Стр 5, кол 10', $status->getText());
    }

    /**
     * Большие значения (999, 999) корректно форматируются.
     */
    public function testGetTextLargeValues(): void
    {
        $status = new CursorPositionStatus(999, 999);
        $this->assertSame('Стр 1000, кол 1000', $status->getText());
    }

    /**
     * ANSI-код цвета — зелёный (\033[92m).
     */
    public function testGetColorCodeGreen(): void
    {
        $status = new CursorPositionStatus(0, 0);
        $this->assertSame("\033[92m", $status->getColorCode());
    }
}
