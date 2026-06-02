<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_values;
use function count;
use function mb_substr;
use function trim;
use function usort;

/**
 * Инструмент получения списка сессий долговременной памяти `.mind` пользователя.
 *
 * Возвращает метаданные из `.mind/<user>/sessions.md`.
 *
 * Пример вызова (LLM):
 * <code>
 * {"tool":"mind.sessions","args":{"limit":20}}
 * </code>
 */
final class MindSessionsTool extends ATool
{
    public function __construct(
        string $name = 'mind.sessions',
        string $description = 'Список сессий долговременной памяти (.mind) текущего пользователя: sessionKey, диапазон времени, count, summary.',
    ) {
        parent::__construct(name: $name, description: $description);
    }

    /**
     * @return ToolProperty[]
     */
    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Максимальное число сессий (по умолчанию 50).',
                required: false,
            ),
        ];
    }

    /**
     * @return string JSON
     */
    public function __invoke(?int $limit = null): string
    {
        $limit = $limit ?? 50;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $agentCfg = $this->getAgentCfg();
        $app = $agentCfg?->getConfigurationApp();
        if ($app === null) {
            try {
                $app = ConfigurationApp::getInstance();
            } catch (\Throwable) {
                $app = null;
            }
        }
        if ($app === null) {
            return JsonHelper::encodeThrow(['error' => 'ConfigurationApp is not available.']);
        }

        $mindDir = $app->getMindDir();
        $userId = $app->getUserId();

        $mind = new UserMindStorage(new MindPaths($mindDir, $userId));
        $items = $mind->getSessionsIndex()->readAll();

        // В `readAll()` сортировка по sessionKey; для UX вернём "последние" сверху по lastCapturedAt.
        $rows = array_values($items);
        usort($rows, static function ($a, $b): int {
            return strcmp($b->getLastCapturedAt(), $a->getLastCapturedAt());
        });

        $out = [];
        $n = 0;
        foreach ($rows as $meta) {
            if ($n >= $limit) {
                break;
            }
            $summary = trim($meta->getSummary());
            if (mb_strlen($summary, 'UTF-8') > 500) {
                $summary = mb_substr($summary, 0, 499, 'UTF-8') . '…';
            }
            $out[] = [
                'sessionKey' => $meta->getSessionKey(),
                'firstCapturedAt' => $meta->getFirstCapturedAt(),
                'lastCapturedAt' => $meta->getLastCapturedAt(),
                'messageCount' => $meta->getMessageCount(),
                'summary' => $summary,
            ];
            $n++;
        }

        return JsonHelper::encodeThrow([
            'count' => count($out),
            'sessions' => $out,
        ]);
    }
}
