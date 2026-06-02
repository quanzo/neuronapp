<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\mind\helpers\MindSummarySessionKeyHelper;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_values;
use function count;
use function mb_strlen;
use function trim;
use function usort;

/**
 * Инструмент поиска по долговременной памяти `.mind` по всем сессиям пользователя.
 *
 * Возвращает список совпавших блоков, сгруппированных по sessionKey (с лимитами по размеру).
 *
 * Пример:
 * <code>
 * {"tool":"mind.search","args":{"query":"моё имя","max_chars":8000,"max_sessions":10}}
 * </code>
 */
final class MindSearchTool extends ATool
{
    public function __construct(
        string $name = 'mind.search',
        string $description = 'Поиск по долговременной памяти (.mind) по всем сессиям пользователя. Возвращает релевантные блоки с sessionKey.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'Строка поиска: regex с разделителями (например "/name/u") или обычный текст.',
                required: true,
            ),
            ToolProperty::make(
                name: 'max_chars',
                type: PropertyType::INTEGER,
                description: 'Максимальный суммарный размер результата в символах (по умолчанию 20000).',
                required: false,
            ),
            ToolProperty::make(
                name: 'max_sessions',
                type: PropertyType::INTEGER,
                description: 'Максимум сессий для сканирования (по умолчанию 20).',
                required: false,
            ),
        ];
    }

    /**
     * @return string JSON
     */
    public function __invoke(string $query, ?int $max_chars = null, ?int $max_sessions = null): string
    {
        $query = trim($query);
        if ($query === '') {
            return JsonHelper::encodeThrow(['error' => 'query must not be empty']);
        }

        $max_chars = $max_chars ?? 20000;
        if ($max_chars < 1000) {
            $max_chars = 1000;
        }
        if ($max_chars > 200000) {
            $max_chars = 200000;
        }

        $max_sessions = $max_sessions ?? 20;
        if ($max_sessions < 1) {
            $max_sessions = 1;
        }
        if ($max_sessions > 200) {
            $max_sessions = 200;
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
        $sessions = array_values($mind->getSessionsIndex()->readAll());
        usort($sessions, static fn($a, $b): int => strcmp($b->getLastCapturedAt(), $a->getLastCapturedAt()));

        $outSessions = [];
        $totalChars = 0;
        $sessionsScanned = 0;

        foreach ($sessions as $meta) {
            if (MindSummarySessionKeyHelper::isSummarySession($meta->getSessionKey())) {
                continue;
            }
            if ($sessionsScanned >= $max_sessions) {
                break;
            }
            $sessionsScanned++;

            $hits = $mind->searchSession($meta->getSessionKey(), $query, 50000);
            if ($hits === []) {
                continue;
            }

            $items = [];
            foreach ($hits as $dto) {
                $payload = [
                    'recordId' => $dto->getRecordId(),
                    'capturedAt' => $dto->getCapturedAt(),
                    'role' => $dto->getRole(),
                    'body' => $dto->getBody(),
                ];
                $len = mb_strlen((string) JsonHelper::encodeThrow($payload), 'UTF-8');
                if ($totalChars + $len > $max_chars) {
                    break 2;
                }
                $totalChars += $len;
                $items[] = $payload;
                if (count($items) >= 20) {
                    // Защита: на одну сессию не возвращаем слишком много.
                    break;
                }
            }

            if ($items === []) {
                continue;
            }

            $outSessions[] = [
                'sessionKey' => $meta->getSessionKey(),
                'summary' => $meta->getSummary(),
                'matches' => $items,
            ];
        }

        return JsonHelper::encodeThrow([
            'query' => $query,
            'sessionsScanned' => $sessionsScanned,
            'sessionsMatched' => count($outSessions),
            'resultCharsApprox' => $totalChars,
            'results' => $outSessions,
        ]);
    }
}
