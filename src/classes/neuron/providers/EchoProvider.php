<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\providers;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * Провайдер-заглушка, который имитирует работу LLM, возвращая последнее сообщение пользователя в качестве ответа.
 *
 * Назначение:
 * - Используется для тестирования и отладки приложений на базе NeuronAI без необходимости обращаться к реальным API.
 * - Позволяет проверить корректность передачи сообщений, работу инструментов (хотя они не выполняются) и потокового вывода.
 *
 * Особенности:
 * - Не требует HTTP-запросов, API-ключей или сетевого подключения.
 * - Полностью реализует интерфейс AIProviderInterface, чтобы быть взаимозаменяемым с другими провайдерами.
 * - Все методы, связанные с мапперами, возвращают простейшие заглушки, так как реальное преобразование данных не требуется.
 * - Сохраняет состояние (системный промпт и инструменты) для совместимости, но игнорирует его при генерации ответа.
 *
 * @package NeuronAI\Providers
 */
class EchoProvider implements AIProviderInterface
{
    /**
     * Системный промпт, установленный через метод systemPrompt().
     * Хранится для соблюдения интерфейса, но не используется в логике эха.
     *
     * @var string|null
     */
    protected ?string $systemPrompt = null;

    /**
     * Массив инструментов (tools), переданных через setTools().
     * Сохраняется, но не оказывает влияния на ответ.
     *
     * @var ToolInterface[]
     */
    protected array $tools = [];

    /**
     * {@inheritDoc}
     *
     * Сохраняет системный промпт во внутреннем свойстве.
     * Поскольку провайдер не отправляет запросы, промпт никуда не передаётся,
     * но метод должен быть реализован для соответствия интерфейсу.
     *
     * @param string|null $prompt Системный промпт или null для сброса.
     * @return AIProviderInterface Тот же экземпляр для цепочки вызовов.
     */
    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Сохраняет переданные инструменты.
     * В реальном провайдере они бы использовались для вызова функций,
     * но в эхо-режиме они игнорируются.
     *
     * @param ToolInterface[] $tools Массив объектов, реализующих ToolInterface.
     * @return AIProviderInterface Тот же экземпляр для цепочки вызовов.
     */
    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Возвращает простейший маппер сообщений, который не выполняет никаких преобразований.
     * Это необходимо, потому что интерфейс требует наличия маппера, но в эхо-режиме
     * мы не отправляем данные во внешний API, поэтому трансформация не нужна.
     *
     * @return MessageMapperInterface Анонимный класс, который возвращает массив сообщений как есть.
     */
    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface {
            /**
             * {@inheritDoc}
             *
             * Возвращает входные сообщения без изменений.
             *
             * @param array $messages Массив сообщений в формате NeuronAI.
             * @return array Те же сообщения.
             */
            public function map(array $messages): array
            {
                return $messages;
            }
        };
    }

    /**
     * {@inheritDoc}
     *
     * Возвращает маппер для инструментов, который также ничего не преобразует.
     * Реальные провайдеры обычно приводят инструменты к формату, ожидаемому API (например, OpenAI functions).
     * Здесь же мы просто возвращаем исходный массив.
     *
     * @return ToolMapperInterface Анонимный класс, возвращающий инструменты без изменений.
     */
    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface {
            /**
             * {@inheritDoc}
             *
             * @param ToolInterface[] $tools Массив инструментов.
             * @return array Те же инструменты (объекты, не сериализованные).
             */
            public function map(array $tools): array
            {
                return $tools;
            }
        };
    }

    /**
     * {@inheritDoc}
     *
     * Основной метод для не-потокового общения с "LLM".
     * Находит последнее сообщение от пользователя в переданном списке и возвращает его
     * содержимое как ответ ассистента.
     *
     * Алгоритм:
     * 1. Из массива сообщений извлекается последнее сообщение с ролью UserMessage.
     * 2. Если такое сообщение найдено, его содержимое становится ответом.
     * 3. Если сообщений пользователя нет, возвращается пустая строка.
     * 4. Результат оборачивается в AssistantMessage.
     *
     * Системный промпт и инструменты игнорируются.
     *
     * @param Message ...$messages Вариативный список сообщений (объекты, наследующие Message).
     * @return Message Объект AssistantMessage с текстом последнего пользовательского ввода.
     */
    public function chat(Message ...$messages): Message
    {
        $lastUserMessage = $this->extractLastUserMessage($messages);
        $content = $lastUserMessage
            ? $lastUserMessage->getContent()
            : '';

        return new AssistantMessage($content);
    }

    /**
     * {@inheritDoc}
     *
     * Потоковый вариант метода chat. Эмулирует поступление ответа частями.
     * Согласно контракту генератора:
     * - В процессе итерации выбрасываются (yield) объекты-чанки (например, TextChunk).
     * - После завершения итерации генератор ДОЛЖЕН вернуть итоговое сообщение (Message).
     *
     * Реализация:
     * - Извлекает последнее пользовательское сообщение.
     * - Немедленно генерирует один чанк TextChunk со всем содержимым (имитация одного "токена").
     * - Завершает итерацию, возвращая AssistantMessage с тем же текстом.
     *
     * При необходимости можно усложнить, например, разбивать текст на отдельные символы
     * или добавлять искусственные задержки для более реалистичного тестирования потоков.
     *
     * @param Message ...$messages Список входящих сообщений.
     * @return Generator<int, TextChunk, mixed, Message> Генератор, возвращающий итоговое сообщение.
     */
    public function stream(Message ...$messages): Generator
    {
        $lastUserMessage = $this->extractLastUserMessage($messages);
        $content = $lastUserMessage
            ? $lastUserMessage->getContent()
            : '';

        // Генерируем один чанк с полным текстом
        $messageId = spl_object_hash($lastUserMessage ?? $this);
        yield new TextChunk($messageId, $content);

        // Возвращаем итоговое сообщение (обязательное требование интерфейса)
        return new AssistantMessage($content);
    }

    /**
     * {@inheritDoc}
     *
     * Метод для получения структурированного вывода (например, под конкретный класс).
     * В данной заглушке мы игнорируем параметры $class и $response_schema,
     * а просто возвращаем эхо-ответ как обычное сообщение.
     *
     * Причины:
     * - Провайдер не поддерживает структурированный вывод, так как не обращается к реальной LLM.
     * - В тестовых сценариях часто достаточно проверить, что метод вызывается с корректными аргументами.
     *
     * @param Message|Message[] $messages Одно сообщение или массив сообщений.
     * @param string $class Имя класса, в который должна быть десериализована структура (игнорируется).
     * @param array<string, mixed> $response_schema Схема ожидаемого ответа (игнорируется).
     * @return Message AssistantMessage с текстом последнего пользовательского сообщения.
     */
    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        // Приводим входные данные к массиву для единообразной обработки
        $messageArray = is_array($messages) ? $messages : [$messages];
        $lastUserMessage = $this->extractLastUserMessage($messageArray);
        $content = $lastUserMessage
            ? $lastUserMessage->getContent()
            : '';

        return new AssistantMessage($content);
    }

    /**
     * {@inheritDoc}
     *
     * Провайдер не использует HTTP-клиент, поэтому метод ничего не делает,
     * кроме возврата самого себя для возможности цепочечного вызова.
     *
     * @param HttpClientInterface $client Любая реализация HTTP-клиента (игнорируется).
     * @return AIProviderInterface Тот же экземпляр.
     */
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        // Клиент не нужен, просто возвращаем $this
        return $this;
    }

    /**
     * Вспомогательный метод для извлечения последнего сообщения пользователя из массива.
     *
     * Проходит по массиву сообщений в обратном порядке и возвращает первый встреченный
     * объект типа UserMessage. Если таких сообщений нет, возвращает null.
     *
     * @param Message[] $messages Массив объектов, наследующих Message.
     * @return Message|null Последнее сообщение пользователя или null.
     */
    protected function extractLastUserMessage(array $messages): ?Message
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof Message && $messages[$i]->getRole() == MessageRole::USER->value) {
                return $messages[$i];
            }
        }
        return null;
    }
}
