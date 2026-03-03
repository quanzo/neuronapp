<?php

declare(strict_types=1);

namespace Tests\Status;

use app\modules\neuron\classes\status\ModeStatus;
use app\modules\neuron\interfaces\StatusInterface;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ModeStatus}.
 *
 * ModeStatus — компонент строки состояния, отображающий текущий режим
 * работы приложения (например, «ВВОД» или «ПРОСМОТР»). Цвет — жёлтый.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\status\ModeStatus}
 */
class ModeStatusTest extends TestCase
{
    /**
     * Класс реализует StatusInterface.
     */
    public function testImplementsInterface(): void
    {
        $status = new ModeStatus('ВВОД');
        $this->assertInstanceOf(StatusInterface::class, $status);
    }

    /**
     * getText() возвращает строку режима, переданную в конструктор.
     */
    public function testGetText(): void
    {
        $status = new ModeStatus('ПРОСМОТР');
        $this->assertSame('ПРОСМОТР', $status->getText());
    }

    /**
     * Пустая строка режима — допустимое значение.
     */
    public function testGetTextEmpty(): void
    {
        $status = new ModeStatus('');
        $this->assertSame('', $status->getText());
    }

    /**
     * ANSI-код цвета — жёлтый (\033[93m).
     */
    public function testGetColorCodeYellow(): void
    {
        $status = new ModeStatus('X');
        $this->assertSame("\033[93m", $status->getColorCode());
    }
}
