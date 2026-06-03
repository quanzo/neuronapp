<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\interfaces\MindSessionSummaryRefresherInterface;
use app\modules\neuron\mind\storage\UserMindStorage;

/**
 * Тестовая заглушка {@see MindSessionSummaryRefresherInterface} с записью вызовов.
 */
final class RecordingMindSessionSummaryRefresher implements MindSessionSummaryRefresherInterface
{
    /** @var list<array{mind: UserMindStorage, sessionKey: string}> */
    private array $calls = [];

    /**
     * {@inheritdoc}
     */
    public function refreshSessionSummary(UserMindStorage $mind, string $sessionKey): void
    {
        $this->calls[] = [
            'mind'       => $mind,
            'sessionKey' => $sessionKey,
        ];

        $meta = $mind->getSessionsIndex()->get($sessionKey);
        if ($meta !== null) {
            $meta->setSummary('stub summary for ' . $sessionKey);
            $mind->getSessionsIndex()->upsert($meta);
        }
    }

    /**
     * @return list<array{mind: UserMindStorage, sessionKey: string}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }
}
