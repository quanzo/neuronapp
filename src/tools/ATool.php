<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\traits\LoggerAwareTrait;
use NeuronAI\Tools\Tool;

/**
 * Базовый класс инструментов модуля с поддержкой логирования.
 *
 * Наследники получают логгер через setLogger() при конфигурировании агента
 * и логируют вызов и завершение (или ошибку) в execute().
 */
abstract class ATool extends Tool
{
    use LoggerAwareTrait;

    /**
     * Выполняет инструмент с логированием начала, успешного завершения и ошибок.
     *
     * @throws \Throwable Пробрасывает исключение после записи в лог
     */
    public function execute(): void
    {
        $logger = $this->getLogger();
        $name = $this->getName();
        $logger->info('Вызов инструмента', ['tool' => $name]);

        try {
            parent::execute();
            $logger->info('Инструмент завершён', ['tool' => $name]);
        } catch (\Throwable $e) {
            $logger->error('Ошибка выполнения инструмента', ['tool' => $name, 'exception' => $e]);
            throw $e;
        }
    }
}
