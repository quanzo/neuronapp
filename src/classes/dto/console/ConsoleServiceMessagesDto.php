<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\console;

/**
 * Коллекция сервисных сообщений для накопления в ходе выполнения LLM-команды.
 *
 * Пример:
 *
 * <code>
 * $service = (new ConsoleServiceMessagesDto())
 *     ->addPlain('Статус "выполняется список" убран');
 * $dto = OutputDto::fromAgent($agent)->withServiceMessages($service);
 * </code>
 */
final class ConsoleServiceMessagesDto
{
    /** @var list<ConsoleServiceMessageDto> */
    private array $messages = [];

    /**
     * Добавляет сообщение в коллекцию.
     */
    public function add(ConsoleServiceMessageDto $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    /**
     * Добавляет plain-сообщение.
     */
    public function addPlain(string $text): self
    {
        return $this->add(ConsoleServiceMessageDto::plain($text));
    }

    /**
     * Добавляет info-сообщение.
     */
    public function addInfo(string $text): self
    {
        return $this->add(ConsoleServiceMessageDto::info($text));
    }

    /**
     * Добавляет comment-сообщение.
     */
    public function addComment(string $text): self
    {
        return $this->add(ConsoleServiceMessageDto::comment($text));
    }

    /**
     * Признак пустой коллекции.
     */
    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    /**
     * Все сообщения в порядке добавления.
     *
     * @return list<ConsoleServiceMessageDto>
     */
    public function getAll(): array
    {
        return $this->messages;
    }

    /**
     * Добавляет сообщения из другой коллекции (после текущих).
     */
    public function merge(self $other): self
    {
        foreach ($other->messages as $message) {
            $this->messages[] = $message;
        }

        return $this;
    }

    /**
     * Сериализует коллекцию для JSON.
     *
     * @return list<array{text: string, level: string}>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->messages as $message) {
            $result[] = $message->toArray();
        }

        return $result;
    }
}
