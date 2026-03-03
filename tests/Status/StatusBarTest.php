<?php

declare(strict_types=1);

namespace Tests\Status;

use app\modules\neuron\classes\status\EmptyStatus;
use app\modules\neuron\classes\status\HistoryCountStatus;
use app\modules\neuron\classes\status\ModeStatus;
use app\modules\neuron\classes\status\StatusBar;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see StatusBar}.
 *
 * StatusBar — компонент, собирающий строку состояния из массива объектов
 * StatusInterface. Между непустыми сегментами вставляется разделитель « | »,
 * каждый сегмент оборачивается ANSI-кодами цвета.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\classes\status\StatusBar}
 */
class StatusBarTest extends TestCase
{
    /**
     * Без статусов render() возвращает пустую строку.
     */
    public function testRenderEmpty(): void
    {
        $bar = new StatusBar();
        $this->assertSame('', $bar->render(80));
    }

    /**
     * Один статус — его текст и ANSI-код цвета присутствуют в результате.
     */
    public function testRenderSingleStatus(): void
    {
        $bar = new StatusBar([new ModeStatus('ВВОД')]);
        $result = $bar->render(80);
        $this->assertStringContainsString('ВВОД', $result);
        $this->assertStringContainsString("\033[93m", $result);
        $this->assertStringContainsString("\033[0m", $result);
    }

    /**
     * Два статуса — между ними вставлен разделитель « | ».
     */
    public function testRenderMultipleStatuses(): void
    {
        $bar = new StatusBar([
            new ModeStatus('ВВОД'),
            new HistoryCountStatus(5),
        ]);
        $result = $bar->render(80);
        $this->assertStringContainsString(' | ', $result);
        $this->assertStringContainsString('ВВОД', $result);
        $this->assertStringContainsString('Сообщений: 5', $result);
    }

    /**
     * EmptyStatus (пустой текст) не попадает в итоговую строку —
     * результат содержит только два непустых сегмента.
     */
    public function testEmptyStatusIsFiltered(): void
    {
        $bar = new StatusBar([
            new ModeStatus('ВВОД'),
            new EmptyStatus(),
            new HistoryCountStatus(3),
        ]);
        $result = $bar->render(80);
        $parts = explode(' | ', $result);
        $this->assertCount(2, $parts);
    }

    /**
     * addStatus() добавляет статус в конец списка.
     */
    public function testAddStatus(): void
    {
        $bar = new StatusBar();
        $bar->addStatus(new ModeStatus('TEST'));
        $result = $bar->render(80);
        $this->assertStringContainsString('TEST', $result);
    }

    /**
     * setStatuses() полностью заменяет текущий набор статусов.
     */
    public function testSetStatuses(): void
    {
        $bar = new StatusBar([new ModeStatus('OLD')]);
        $bar->setStatuses([new ModeStatus('NEW')]);
        $result = $bar->render(80);
        $this->assertStringContainsString('NEW', $result);
        $this->assertStringNotContainsString('OLD', $result);
    }

    /**
     * Все статусы пусты (EmptyStatus) — результат — пустая строка.
     */
    public function testAllEmptyStatusesResultInEmptyString(): void
    {
        $bar = new StatusBar([new EmptyStatus(), new EmptyStatus()]);
        $this->assertSame('', $bar->render(80));
    }
}
