<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\dto\tools;

use app\modules\neuron\interfaces\IArrayable;

/**
 * DTO результата поиска по полной истории чата.
 *
 * Используется инструментом chat_history.grep для поиска строки/regex в сообщениях истории.
 *
 * Формат сериализации (toArray):
 * [
 *     'pattern'           => string,
 *     'caseInsensitive'   => bool,
 *     'includeToolMessages' => bool,
 *     'matches'           => array<int, array>, // ChatHistoryGrepMatchDto::toArray()
 *     'truncated'         => bool,              // true, если лимит maxMatches достигнут
 *     'totalMatches'      => int,               // найдено совпадений (может быть > count(matches), если усечено)
 *     'messagesSearched'  => int,               // сколько сообщений просмотрено
 * ]
 */
final class ChatHistoryGrepResultDto implements IArrayable
{
    /**
     * @param string                   $pattern Паттерн поиска (regex или текст).
     * @param bool                     $caseInsensitive Игнорировать регистр.
     * @param bool                     $includeToolMessages Искать ли в tool-call/tool-result сообщениях.
     * @param ChatHistoryGrepMatchDto[] $matches Список совпадений (в пределах лимита).
     * @param bool                     $truncated Признак усечения по maxMatches.
     * @param int                      $totalMatches Общее число найденных совпадений (может быть больше лимита).
     * @param int                      $messagesSearched Количество просмотренных сообщений.
     */
    public function __construct(
        public readonly string $pattern,
        public readonly bool $caseInsensitive,
        public readonly bool $includeToolMessages,
        public readonly array $matches,
        public readonly bool $truncated,
        public readonly int $totalMatches,
        public readonly int $messagesSearched,
    ) {
    }

    /**
     * Преобразует DTO в массив для сериализации.
     *
     * @return array{
     *   pattern:string,
     *   caseInsensitive:bool,
     *   includeToolMessages:bool,
     *   matches:array<int, array>,
     *   truncated:bool,
     *   totalMatches:int,
     *   messagesSearched:int
     * }
     */
    public function toArray(): array
    {
        return [
            'pattern' => $this->pattern,
            'caseInsensitive' => $this->caseInsensitive,
            'includeToolMessages' => $this->includeToolMessages,
            'matches' => array_map(
                static fn(ChatHistoryGrepMatchDto $m): array => $m->toArray(),
                $this->matches,
            ),
            'truncated' => $this->truncated,
            'totalMatches' => $this->totalMatches,
            'messagesSearched' => $this->messagesSearched,
        ];
    }
}
