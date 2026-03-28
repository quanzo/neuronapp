<?php

declare(strict_types=1);

namespace Tests\Support;

use app\modules\neuron\helpers\LlmCycleHelper;
use app\modules\neuron\classes\config\ConfigurationApp;
use Generator;
use app\modules\neuron\helpers\RunStateCheckpointHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Тестовый провайдер, имитирующий вызов инструмента `todo_goto` со стороны LLM.
 *
 * Пример:
 * - `setGotoPlan([1 => 3])` — на первом user-сообщении вызовет `todo_goto(point=3)`;
 * - далее вернёт обычный assistant-ответ с echo-контентом.
 */
final class TodoGotoSpyProvider implements AIProviderInterface
{
    /**
     * @var list<array{content: string}>
     */
    public static array $calls = [];

    /**
     * @var array<int,int>
     */
    private static array $gotoPlan = [];

    /**
     * @var ToolInterface[]
     */
    private array $tools = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$gotoPlan = [];
    }

    /**
     * Устанавливает план вызова goto: [номер_вызова => point].
     *
     * @param array<int,int> $plan
     */
    public static function setGotoPlan(array $plan): void
    {
        self::$gotoPlan = $plan;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface {
            public function map(array $messages): array
            {
                return $messages;
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface {
            public function map(array $tools): array
            {
                return $tools;
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        $content = $this->extractLastUserContent($messages);
        $this->recordAndMaybeGoto($content);

        return new AssistantMessage($content);
    }

    public function stream(Message ...$messages): Generator
    {
        $content = $this->extractLastUserContent($messages);
        $this->recordAndMaybeGoto($content);

        $messageId = spl_object_hash($this);
        yield new TextChunk($messageId, $content);

        return new AssistantMessage($content);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $array = is_array($messages) ? $messages : [$messages];
        $content = $this->extractLastUserContent($array);
        $this->recordAndMaybeGoto($content);

        return new AssistantMessage($content);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    /**
     * @param Message[] $messages
     */
    private function extractLastUserContent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if ($m instanceof Message && $m->getRole() === MessageRole::USER->value) {
                return (string) ($m->getContent() ?? '');
            }
        }

        return '';
    }

    /**
     * Записывает только пользовательские тексты todo; индекс goto совпадает с номером такого вызова.
     *
     * @param string $content Текст последнего user-сообщения.
     */
    private function recordAndMaybeGoto(string $content): void
    {
        if ($content === LlmCycleHelper::MSG_CHECK_WORK2 || $content === LlmCycleHelper::MSG_RESULT) {
            return;
        }

        self::$calls[] = ['content' => $content];

        $callIndex = count(self::$calls);
        if (isset(self::$gotoPlan[$callIndex])) {
            $this->invokeGotoTool(self::$gotoPlan[$callIndex]);
        }
    }

    /**
     * Ищет среди подключённых инструментов `todo_goto` и вызывает его.
     */
    private function invokeGotoTool(int $targetPoint): void
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() !== 'todo_goto') {
                continue;
            }
            $tool($targetPoint, 'auto-goto-from-test-provider');
            return;
        }

        $sessionKey = ConfigurationApp::getInstance()->getSessionKey();
        $state = RunStateCheckpointHelper::read($sessionKey);
        if ($state !== null) {
            $state->setGotoRequestedTodoIndex($targetPoint - 1)->write();
        }
    }
}
