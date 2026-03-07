<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

use app\modules\neuron\classes\logger\NullLogger;
use Psr\Log\LoggerInterface;

/**
 * Трейт для внедрения логгера в сущность.
 *
 * Предоставляет свойство logger и методы getLogger/setLogger.
 * Если логгер не установлен, getLogger() возвращает NullLogger.
 */
trait LoggerAwareTrait
{
    /**
     * Экземпляр логгера. null — использовать NullLogger.
     *
     * @var LoggerInterface|null
     */
    public ?LoggerInterface $logger = null;

    /**
     * Возвращает текущий логгер или NullLogger, если логгер не задан.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * Устанавливает логгер (fluent).
     *
     * @param LoggerInterface $logger
     * @return static
     */
    public function setLogger(LoggerInterface $logger): static
    {
        $this->logger = $logger;
        return $this;
    }
}
