<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\dto\config;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\helpers\OptionsHelper;

/**
 * DTO блока `mind` в конфигурации приложения или агента.
 *
 * Значение `null` у поля означает, что параметр не задан в конфигурации.
 * Эффективные настройки для агента: {@see self::merge()} поверх app (агент приоритетнее).
 *
 * Пример:
 *
 * <code>
 * $appMind = MindConfigDto::fromConfigurationApp($app);
 * $agentMind = MindConfigDto::fromConfigArray(['collect' => true]);
 * $effective = MindConfigDto::resolveEffective($app, $agentCfg);
 * $effective->resolveCollect();
 * </code>
 */
final class MindConfigDto
{
    /**
     * @param bool|null                        $collect         Сбор в `.mind` или null, если не задано.
     * @param MindSessionSummaryConfigDto|null $sessionSummary  Блок session_summary или null целиком.
     */
    public function __construct(
        private readonly ?bool $collect = null,
        private readonly ?MindSessionSummaryConfigDto $sessionSummary = null,
    ) {
    }

    /**
     * Пустая конфигурация (все параметры не заданы).
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Создаёт DTO из массива `mind` (config.jsonc или PHP-конфиг агента).
     *
     * @param array<string, mixed> $data Блок `mind`.
     */
    public static function fromConfigArray(array $data): self
    {
        if ($data === []) {
            return self::empty();
        }

        $collect = null;
        if (array_key_exists('collect', $data)) {
            $collect = OptionsHelper::toBool($data['collect']);
        }

        $sessionSummary = null;
        if (array_key_exists('session_summary', $data) && is_array($data['session_summary'])) {
            $sessionSummary = MindSessionSummaryConfigDto::fromConfigArray($data['session_summary']);
        }

        return new self($collect, $sessionSummary);
    }

    /**
     * Создаёт DTO из конфигурации приложения.
     *
     * Только для bootstrap {@see ConfigurationApp::getMindConfig()}; для runtime merge
     * используйте {@see self::resolveEffective()}.
     */
    public static function fromConfigurationApp(ConfigurationApp $app): self
    {
        $mind = $app->get('mind');
        if (!is_array($mind)) {
            return self::empty();
        }

        return self::fromConfigArray($mind);
    }

    /**
     * Возвращает effective-конфигурацию mind: explicit → app + agent merge → только app.
     *
     * @param ConfigurationApp           $app      Конфигурация приложения.
     * @param ConfigurationAgent|null  $agent    Агент шага LLM (опционально).
     * @param MindConfigDto|null       $explicit Готовый effective (перекрывает merge).
     */
    public static function resolveEffective(
        ConfigurationApp $app,
        ?ConfigurationAgent $agent = null,
        ?MindConfigDto $explicit = null,
    ): MindConfigDto {
        if ($explicit !== null) {
            return $explicit;
        }

        $base = $app->getMindConfig();
        if ($agent === null) {
            return $base;
        }

        $agentMind = $agent->getMindConfig();
        if ($agentMind === null) {
            return $base;
        }

        return $base->merge($agentMind);
    }

    /**
     * Сливает настройки: non-null поля `$overlay` (агент) перекрывают `$this` (app).
     *
     * @param self|null $overlay Конфигурация агента или null (без изменений).
     */
    public function merge(?self $overlay): self
    {
        if ($overlay === null) {
            return new self($this->collect, $this->sessionSummary);
        }

        $baseSummary = $this->sessionSummary ?? MindSessionSummaryConfigDto::empty();
        $overlaySummary = $overlay->sessionSummary;

        return new self(
            $overlay->collect ?? $this->collect,
            $overlaySummary === null ? $this->sessionSummary : $baseSummary->merge($overlaySummary),
        );
    }

    /**
     * Возвращает признак сбора в `.mind` с учётом default при null.
     *
     * @param bool $default Значение по умолчанию, если `collect` не задан.
     */
    public function resolveCollect(bool $default = false): bool
    {
        if ($this->collect === null) {
            return $default;
        }

        return $this->collect;
    }

    /**
     * Возвращает блок session_summary для резолверов (никогда null — empty при отсутствии).
     */
    public function resolveSessionSummary(): MindSessionSummaryConfigDto
    {
        return $this->sessionSummary ?? MindSessionSummaryConfigDto::empty();
    }

    /**
     * Возвращает сырое значение collect (null = не задано).
     */
    public function getCollect(): ?bool
    {
        return $this->collect;
    }

    /**
     * Возвращает сырой блок session_summary (null = не задано).
     */
    public function getSessionSummary(): ?MindSessionSummaryConfigDto
    {
        return $this->sessionSummary;
    }
}
