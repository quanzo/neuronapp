<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\events;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\interfaces\IArrayable;
use Stringable;

/**
 * Базовый DTO события.
 *
 * Содержит общие поля event-контекста (sessionKey, runId, timestamp, agent)
 * и используется как единый корень иерархии для всех специализированных DTO событий.
 *
 * Реализует `Stringable`: при приведении к строке выводит человекочитаемое
 * сообщение в формате `[Tag] key=value | key=value`, удобное для
 * просмотра в логе и автоматического парсинга скриптами.
 *
 * Пример использования:
 * ```php
 * $dto = (new BaseEventDto())
 *     ->setSessionKey('20260324-120000-123456-0')
 *     ->setRunId('abc123');
 *
 * echo (string) $dto;
 * // [BaseEvent] runId=abc123 | agent= | ts=
 * ```
 */
class BaseEventDto implements IArrayable, Stringable
{
    private string $sessionKey = '';
    private string $runId = '';
    private string $timestamp = '';
    private ?ConfigurationAgent $agent = null;

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
     * @param string $sessionKey Идентификатор сессии (формат `YYYYMMDD-HHMMSS-PID-N`).
     */
    public function setSessionKey(string $sessionKey): self
    {
        $this->sessionKey = $sessionKey;
        return $this;
    }

    /**
     * Возвращает идентификатор run.
     */
    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * Устанавливает идентификатор run.
     *
     * @param string $runId MD5-хеш запуска или пустая строка для вложенных run.
     */
    public function setRunId(string $runId): self
    {
        $this->runId = $runId;
        return $this;
    }

    /**
     * Возвращает время события в формате ATOM.
     */
    public function getTimestamp(): string
    {
        return $this->timestamp;
    }

    /**
     * Устанавливает время события в формате ATOM.
     *
     * @param string $timestamp Время в формате DateTimeInterface::ATOM.
     */
    public function setTimestamp(string $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * Возвращает конфигурацию агента (если доступна).
     */
    public function getAgent(): ?ConfigurationAgent
    {
        return $this->agent;
    }

    /**
     * Устанавливает конфигурацию агента.
     *
     * @param ?ConfigurationAgent $agent Конфигурация агента или null.
     */
    public function setAgent(?ConfigurationAgent $agent): self
    {
        $this->agent = $agent;
        return $this;
    }

    /**
     * Преобразует DTO в массив для логирования/сериализации.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runId'      => $this->runId,
            'timestamp'  => $this->timestamp,
            'agentName'  => $this->agent?->getAgentName(),
        ];
    }

    /**
     * Возвращает человекочитаемое строковое представление события.
     *
     * Формат: `[Tag] key1=value1 | key2=value2 | ...`
     *
     * - Tag определяется через {@see getEventTag()}.
     * - Пары key=value формируются через {@see buildStringParts()}.
     * - Значения со спецсимволами (`|`, `"`, перевод строки) экранируются кавычками.
     */
    public function __toString(): string
    {
        $tag   = $this->getEventTag();
        $parts = $this->buildStringParts();

        $formatted = [];
        foreach ($parts as $key => $value) {
            $formatted[] = $key . '=' . self::escapeValue((string) $value);
        }

        return '[' . $tag . '] ' . implode(' | ', $formatted);
    }

    /**
     * Возвращает тег события для строкового представления.
     *
     * По умолчанию — короткое имя класса без суффикса `Dto`.
     * Подклассы могут переопределять для кастомного тега.
     */
    protected function getEventTag(): string
    {
        $class = static::class;
        $short = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;

        if (str_ends_with($short, 'Dto')) {
            $short = substr($short, 0, -3);
        }

        return $short;
    }

    /**
     * Возвращает ассоциативный массив пар key=>value для строкового представления.
     *
     * Подклассы добавляют свои поля перед вызовом parent::buildStringParts()
     * и сливают результат, чтобы базовые поля шли в конце строки.
     *
     * @return array<string, string|int|float|null>
     */
    protected function buildStringParts(): array
    {
        return [
            'runId' => $this->runId,
            'agent' => $this->agent?->getAgentName() ?? '',
            'ts'    => $this->timestamp,
        ];
    }

    /**
     * Экранирует значение для строкового представления.
     *
     * Если значение содержит пробелы, `|`, кавычки или переносы строк,
     * оборачивает его в двойные кавычки с экранированием внутренних кавычек.
     */
    private static function escapeValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (preg_match('/[\s|"\\\\]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
