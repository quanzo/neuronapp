<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

use app\modules\neuron\classes\logger\ContextualLogger;
use Psr\Log\LoggerInterface;

/**
 * Трейт для внедрения логгера в сущность.
 */
trait LoggerAwareContextualTrait
{
    /**
     * Возвращает контекст для логирования
     *
     * @return array
     */
    public function getLogContext(): array
    {
        return [];
    }

    /**
     * Возвращает логгер с автоматически подставляемым контекстом (agent, session).
     *
     * @return LoggerInterface
     */
    public function getLoggerWithContext(): LoggerInterface
    {
        return new ContextualLogger($this->getLogger(), $this->getLogContext());
    }
}
