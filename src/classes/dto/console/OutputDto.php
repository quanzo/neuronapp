<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\helpers\ChatHistoryEditHelper;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ChatHistoryException;
use Throwable;

/**
 * DTO унифицированного вывода консольных LLM-команд.
 *
 * Содержит текст ответа, ключ сессии, сервисные сообщения, опциональные
 * метки времени выполнения, сообщение об ошибке и (для `orchestrate`) вложенный
 * результат оркестратора.
 *
 * Пример:
 *
 * <code>
 * $service = (new ConsoleServiceMessagesDto())->addPlain('Готово');
 * $dto = OutputDto::fromAgent($agentCfg)->withServiceMessages($service);
 * </code>
 */
class OutputDto
{
    private ?OrchestratorResultDto $orchestrator = null;

    private ?OutputExecutionTimingDto $executionTiming = null;

    private ConsoleServiceMessagesDto $serviceMessages;

    /**
     * @param string $errorMessage Сообщение об ошибке; пустая строка — успех.
     * @param string $response     Текст ответа LLM или итоговый результат.
     * @param string $sessionKey   Ключ сессии.
     */
    public function __construct(
        private string $errorMessage = '',
        private string $response = '',
        private string $sessionKey = ''
    ) {
        $this->serviceMessages = new ConsoleServiceMessagesDto();
    }

    /**
     * Признак ошибки команды (непустой errorMessage).
     */
    public function isError(): bool
    {
        return $this->errorMessage !== '';
    }

    /**
     * Сообщение об ошибке.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Текст ответа / результата.
     */
    public function getResponse(): string
    {
        return $this->response;
    }

    /**
     * Ключ сессии.
     */
    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Сервисные сообщения, накопленные в ходе выполнения команды.
     */
    public function getServiceMessages(): ConsoleServiceMessagesDto
    {
        return $this->serviceMessages;
    }

    /**
     * Метки времени выполнения команды (если заданы в finish()).
     */
    public function getExecutionTiming(): ?OutputExecutionTimingDto
    {
        return $this->executionTiming;
    }

    /**
     * Возвращает копию DTO с метками времени выполнения.
     */
    public function withExecutionTiming(OutputExecutionTimingDto $timing): self
    {
        $clone = $this->copy();
        $clone->executionTiming = $timing;

        return $clone;
    }

    /**
     * Вложенный результат оркестратора (только для команды orchestrate).
     */
    public function getOrchestrator(): ?OrchestratorResultDto
    {
        return $this->orchestrator;
    }

    /**
     * Устанавливает вложенный результат оркестратора.
     */
    public function setOrchestrator(?OrchestratorResultDto $orchestrator): self
    {
        $this->orchestrator = $orchestrator;

        return $this;
    }

    /**
     * Добавляет сервисное сообщение.
     */
    public function addServiceMessage(ConsoleServiceMessageDto $message): self
    {
        $this->serviceMessages->add($message);

        return $this;
    }

    /**
     * Добавляет plain-сервисное сообщение.
     */
    public function addServicePlain(string $text): self
    {
        $this->serviceMessages->addPlain($text);

        return $this;
    }

    /**
     * Добавляет info-сервисное сообщение.
     */
    public function addServiceInfo(string $text): self
    {
        $this->serviceMessages->addInfo($text);

        return $this;
    }

    /**
     * Добавляет comment-сервисное сообщение.
     */
    public function addServiceComment(string $text): self
    {
        $this->serviceMessages->addComment($text);

        return $this;
    }

    /**
     * Возвращает копию DTO с подмешанными сервисными сообщениями.
     */
    public function withServiceMessages(ConsoleServiceMessagesDto $messages): self
    {
        $clone = $this->copy();
        $merged = (new ConsoleServiceMessagesDto())->merge($messages)->merge($this->serviceMessages);
        $clone->serviceMessages = $merged;

        return $clone;
    }

    /**
     * Сериализует DTO в массив для JSON-вывода.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $res = [
            'response'   => $this->response,
            'sessionKey' => $this->sessionKey,
        ];
        if ($this->errorMessage !== '') {
            $res['errorMessage'] = $this->errorMessage;
        }
        if (!$this->serviceMessages->isEmpty()) {
            $res['serviceMessages'] = $this->serviceMessages->toArray();
        }
        if ($this->orchestrator !== null) {
            $res['orchestrator'] = $this->orchestrator->toArray();
        }
        if ($this->executionTiming !== null) {
            $res = array_merge($res, $this->executionTiming->toArray());
        }

        return $res;
    }

    /**
     * Создаёт DTO с сообщением об ошибке (валидация CLI и т.п.).
     *
     * @param string $errorMessage Текст ошибки.
     * @param string $sessionKey   Ключ сессии (если известен).
     */
    public static function fromError(string $errorMessage, string $sessionKey = ''): self
    {
        return new self(
            errorMessage: $errorMessage,
            sessionKey: $sessionKey,
        );
    }

    /**
     * Ошибка: не указана опция --agent.
     */
    public static function fromMissingAgentOption(string $sessionKey = ''): self
    {
        return self::fromError('Не указан агент. Используйте --agent.', $sessionKey);
    }

    /**
     * Ошибка: не указана опция --message.
     */
    public static function fromMissingMessageOption(string $sessionKey = ''): self
    {
        return self::fromError('Не указано сообщение. Используйте --message.', $sessionKey);
    }

    /**
     * Ошибка: агент не найден по имени.
     */
    public static function fromAgentNotFound(string $agentName, string $sessionKey = ''): self
    {
        return self::fromError(
            sprintf('Агент "%s" не найден.', $agentName),
            $sessionKey,
        );
    }

    /**
     * Ошибка: агент-суммаризатор не найден по имени.
     */
    public static function fromSummarizerAgentNotFound(string $agentName, string $sessionKey): self
    {
        return self::fromError(
            sprintf('Агент-суммаризатор "%s" не найден.', $agentName),
            $sessionKey,
        );
    }

    /**
     * Ошибка: неверный формат ключа сессии.
     */
    public static function fromInvalidSessionKey(string $sessionKey, string $optionLabel = 'session_id'): self
    {
        return self::fromError(
            sprintf(
                'Неверный формат %s. Ожидается формат %s.',
                $optionLabel,
                ConfigurationApp::describeSessionKeyFormat(),
            ),
            $sessionKey,
        );
    }

    /**
     * Ошибка: сессия не найдена в хранилище.
     */
    public static function fromSessionNotFound(string $sessionId): self
    {
        return self::fromError(
            sprintf('Сессия с session_id "%s" не найдена.', $sessionId),
            $sessionId,
        );
    }

    /**
     * Ошибка: незавершённое выполнение списка в сессии.
     */
    public static function fromUnfinishedRun(
        string $todolistName,
        string $sessionKey,
        bool $withResumeHint = false,
    ): self {
        $message = $withResumeHint
            ? sprintf(
                'В сессии обнаружено незавершённое выполнение списка "%s". Укажите --resume для продолжения или --abort для сброса.',
                $todolistName,
            )
            : sprintf(
                'В сессии обнаружено незавершённое выполнение списка "%s".',
                $todolistName,
            );

        return self::fromError($message, $sessionKey);
    }

    /**
     * Создаёт DTO из готового текстового ответа.
     *
     * @param string $response   Текст результата.
     * @param string $sessionKey Ключ сессии.
     */
    public static function fromResponse(string $response, string $sessionKey): self
    {
        return new self(
            response: $response,
            sessionKey: $sessionKey,
        );
    }

    /**
     * Создаёт DTO из последнего сообщения истории чата агента.
     */
    public static function fromAgent(ConfigurationAgent $configAgent): self
    {
        $history = $configAgent->getChatHistory();
        try {
            $lastMessage = ChatHistoryEditHelper::getLastMessage($history);
        } catch (ChatHistoryException) {
            $lastMessage = false;
        }
        if (!$lastMessage instanceof Message) {
            return new self(
                errorMessage: 'Нет ответа в истории чата.',
                sessionKey: $configAgent->getSessionKey()
            );
        }
        $content = $lastMessage->getContent();

        return new self(
            response: is_string($content) ? $content : (string) $content,
            sessionKey: $configAgent->getSessionKey()
        );
    }

    /**
     * Создаёт DTO из исключения при выполнении LLM.
     */
    public static function fromException(Throwable $error, ConfigurationAgent $configAgent): self
    {
        return new self(
            errorMessage: $error->getMessage(),
            sessionKey: $configAgent->getSessionKey()
        );
    }

    /**
     * Создаёт DTO из исключения с явным sessionKey (когда агент недоступен).
     */
    public static function fromExceptionWithSessionKey(Throwable $error, string $sessionKey = ''): self
    {
        return new self(
            errorMessage: $error->getMessage(),
            sessionKey: $sessionKey,
        );
    }

    /**
     * Создаёт DTO из результата оркестратора TodoList.
     */
    public static function fromOrchestrator(OrchestratorResultDto $result): self
    {
        $message = $result->getMessage();
        $response = '';
        if ($message !== null) {
            $content = $message->getContent();
            $response = is_string($content) ? $content : (string) $content;
        }

        return (new self(
            response: $response,
            sessionKey: $result->getSessionKey(),
        ))->setOrchestrator($result);
    }

    /**
     * Копирует DTO без глубокого копирования вложенных объектов.
     */
    private function copy(): self
    {
        $clone = new self($this->errorMessage, $this->response, $this->sessionKey);
        $clone->orchestrator = $this->orchestrator;
        $clone->executionTiming = $this->executionTiming;
        $clone->serviceMessages = new ConsoleServiceMessagesDto();
        $clone->serviceMessages->merge($this->serviceMessages);

        return $clone;
    }
}
