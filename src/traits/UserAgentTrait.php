<?php

declare(strict_types=1);

namespace app\modules\neuron\traits;

/**
 * Трейт для внедрения User-Agent.
 */
trait UserAgentTrait
{
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';

    /**
     * Возвращает user agent
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Устанавливает user agent
     *
     * @param string $ua
     * @return static
     */
    public function setUserAgent(string $ua): static
    {
        $this->userAgent = $ua;
        return $this;
    }
}
