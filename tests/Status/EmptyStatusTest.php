<?php

declare(strict_types=1);

namespace Tests\Status;

use app\modules\neuron\classes\status\EmptyStatus;
use app\modules\neuron\interfaces\StatusInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see EmptyStatus}.
 *
 * EmptyStatus — заглушка для строки состояния: всегда возвращает пустой текст
 * и код сброса ANSI-цвета. Используется для временного отключения сегмента.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\status\EmptyStatus}
 */
class EmptyStatusTest extends TestCase
{
    /**
     * Класс реализует StatusInterface.
     */
    public function testImplementsInterface(): void
    {
        $status = new EmptyStatus();
        $this->assertInstanceOf(StatusInterface::class, $status);
    }

    /**
     * getText() всегда возвращает пустую строку.
     */
    public function testGetTextEmpty(): void
    {
        $status = new EmptyStatus();
        $this->assertSame('', $status->getText());
    }

    /**
     * ANSI-код — сброс цвета (\033[0m).
     */
    public function testGetColorCodeReset(): void
    {
        $status = new EmptyStatus();
        $this->assertSame("\033[0m", $status->getColorCode());
    }
}
