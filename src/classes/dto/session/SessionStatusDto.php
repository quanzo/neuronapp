<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\session;

use app\modules\neuron\classes\dto\run\RunStateDto;

/**
 * DTO статуса сессии приложения.
 *
 * Под «статусом» подразумевается состояние выполнения TodoList в рамках сессии,
 * сохранённое в чекпоинте `.store/run_state_{sessionKey}_{agent}.json` и представленное
 * как {@see RunStateDto}. Если чекпоинта нет — `runState` будет null.
 *
 * Пример использования:
 *
 * <code>
 * $status = (new SessionStatusDto())
 *     ->setSessionKey($sessionKey)
 *     ->setRunState($runStateDto);
 *
 * if ($status->isRunning()) {
 *     // есть незавершённый запуск
 * }
 * </code>
 */
final class SessionStatusDto
{
    private string $sessionKey = '';
    private ?RunStateDto $runState = null;

    /**
     * Возвращает ключ сессии.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Устанавливает ключ сессии.
     *
     * @return $this
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает DTO состояния выполнения run или null, если чекпоинта нет.
     */
    public function getRunState(): ?RunStateDto
    {
        return $this->runState;
    }

    /**
     * Устанавливает DTO состояния выполнения run.
     *
     * @return $this
     */
    public function setRunState(?RunStateDto $runState): self
    {
        $this->runState = $runState;
        return $this;
    }

    /**
     * Возвращает true, если в сессии есть незавершённый run.
     */
    public function isRunning(): bool
    {
        return $this->runState !== null && !$this->runState->isFinished();
    }

    /**
     * Возвращает true, если run завершён успешно (чекпоинт существует и finished=true).
     */
    public function isFinished(): bool
    {
        return $this->runState !== null && $this->runState->isFinished();
    }
}
