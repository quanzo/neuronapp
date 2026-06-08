<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\neuron\history\AbstractFullChatHistory;
use Throwable;

/**
 * DTO для вывода в консоль результата работы
 */
class OutputDto
{
    public function __construct(
        private string $errorMessage = '',
        private string $response = '',
        private string $sessionKey = ''
    ) {}

    public function isError(): bool {
        return !!$this->errorMessage;
    }

    public function getErrorMessage(): string {
        return $this->errorMessage;
    }

    public function getResponse(): string {
        return $this->response;
    }

    public function getSessionKey(): string {
        return $this->sessionKey;
    }

    public function toArray(): array {
        $res = [
            'response'   => $this->response,
            'sessionKey' => $this->sessionKey
        ];
        if ($this->errorMessage) {
            $res['errorMessage'] = $this->errorMessage;
        }
        return $res;
    }

    public static function fromAgent(ConfigurationAgent $configAgent): self {
        $history = $configAgent->getChatHistory();
        $lastMessage = $history->getLastMessage();
        if ($lastMessage === false) {
            return new self(
                errorMessage: 'Нет ответа в истории чата.',
                sessionKey: $configAgent->getSessionKey()
            );
        }
        $content = $lastMessage->getContent();
        return new self(
            response: $content,
            sessionKey: $configAgent->getSessionKey()
        );
    }

    public static function fromException(Throwable $error, ConfigurationAgent $configAgent) {
        return new self(
            errorMessage: $error->getMessage(),
            sessionKey: $configAgent->getSessionKey()
        );
    }
}
