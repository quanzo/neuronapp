<?php

declare(strict_types=1);

namespace app\modules\neuron\tools;

use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\helpers\JsonHelper;
use app\modules\neuron\mind\storage\MindPaths;
use app\modules\neuron\mind\storage\UserMindStorage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

use function array_slice;
use function count;
use function max;

/**
 * Инструмент просмотра конкретной сессии `.mind`.
 *
 * Режимы:
 * - `recordId` задан → вернуть запись по id
 * - иначе вернуть последние N записей (`limit`, по умолчанию 10)
 *
 * Пример:
 * <code>
 * {"tool":"mind.session.view","args":{"sessionKey":"...","limit":10}}
 * {"tool":"mind.session.view","args":{"sessionKey":"...","recordId":3}}
 * </code>
 */
final class MindSessionViewTool extends ATool
{
    public function __construct(
        string $name = 'mind.session.view',
        string $description = 'Просмотр сессии долговременной памяти (.mind): по recordId или последние N сообщений.',
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
                name: 'sessionKey',
                type: PropertyType::STRING,
                description: 'Полный sessionKey сессии.',
                required: true,
            ),
            ToolProperty::make(
                name: 'recordId',
                type: PropertyType::INTEGER,
                description: 'Если указан — вернуть конкретную запись по id (в пределах сессии).',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Число последних сообщений (по умолчанию 10).',
                required: false,
            ),
        ];
    }

    /**
     * @return string JSON
     */
    public function __invoke(string $sessionKey, ?int $recordId = null, ?int $limit = null): string
    {
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

        $mind = new UserMindStorage(new MindPaths($app->getMindDir(), $app->getUserId()));
        $session = $mind->openSession($sessionKey);

        if ($recordId !== null) {
            $dto = $session->getByRecordId((int) $recordId);
            if ($dto === null) {
                return JsonHelper::encodeThrow([
                    'error' => 'record not found',
                    'sessionKey' => $sessionKey,
                    'recordId' => (int) $recordId,
                ]);
            }
            return JsonHelper::encodeThrow([
                'sessionKey' => $sessionKey,
                'record' => [
                    'recordId' => $dto->getRecordId(),
                    'capturedAt' => $dto->getCapturedAt(),
                    'role' => $dto->getRole(),
                    'body' => $dto->getBody(),
                ],
            ]);
        }

        $limit = $limit ?? 10;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $all = $session->readAll();
        $tail = array_slice($all, max(0, count($all) - $limit));

        $out = [];
        foreach ($tail as $dto) {
            $out[] = [
                'recordId' => $dto->getRecordId(),
                'capturedAt' => $dto->getCapturedAt(),
                'role' => $dto->getRole(),
                'body' => $dto->getBody(),
            ];
        }

        return JsonHelper::encodeThrow([
            'sessionKey' => $sessionKey,
            'count' => count($out),
            'records' => $out,
        ]);
    }
}
