<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\helpers\ChatHistoryCopyHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для {@see ChatHistoryCopyHelper}.
 *
 * ChatHistoryCopyHelper — перенос сообщений между реализациями {@see ChatHistoryInterface}
 * с опциональным исключением хвоста истории.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\helpers\ChatHistoryCopyHelper}
 */
final class ChatHistoryCopyHelperTest extends TestCase
{
    /**
     * copy() с excludeLast копирует ожидаемое число сообщений и сохраняет порядок.
     *
     * @param list<string> $contents    Тексты сообщений в исходной истории (чередование user/assistant).
     * @param int          $excludeLast Сколько последних сообщений не копировать.
     * @param list<string> $expected    Ожидаемые тексты в целевой истории.
     */
    #[DataProvider('copyExcludeLastProvider')]
    public function testCopyExcludeLast(array $contents, int $excludeLast, array $expected): void
    {
        $from = new InMemoryChatHistory();
        $this->addAlternatingMessages($from, $contents);

        $to = new InMemoryChatHistory();
        ChatHistoryCopyHelper::copy($from, $to, $excludeLast);

        $copied = $to->getMessages();
        $this->assertCount(count($expected), $copied);
        foreach ($expected as $i => $text) {
            $this->assertSame($text, (string) $copied[$i]->getContent());
        }
    }

    /**
     * Наборы данных для testCopyExcludeLast: граничные и некорректные excludeLast.
     *
     * @return iterable<string, array{list<string>, int, list<string>}>
     */
    public static function copyExcludeLastProvider(): iterable
    {
        yield 'empty history exclude 0' => [[], 0, []];
        yield 'empty history exclude 1' => [[], 1, []];
        yield 'single message exclude 0' => [['only'], 0, ['only']];
        yield 'single message exclude 1' => [['only'], 1, []];
        yield 'three messages exclude 0' => [['a', 'b', 'c'], 0, ['a', 'b', 'c']];
        yield 'three messages exclude 1' => [['a', 'b', 'c'], 1, ['a', 'b']];
        yield 'three messages exclude 2' => [['a', 'b', 'c'], 2, ['a']];
        yield 'three messages exclude 3' => [['a', 'b', 'c'], 3, []];
        yield 'three messages exclude greater than count' => [['a', 'b', 'c'], 10, []];
        yield 'negative excludeLast treated as 0' => [['x', 'y'], -1, ['x', 'y']];
        yield 'two messages exclude 1 keeps first' => [['first', 'last'], 1, ['first']];
    }

    /**
     * copy() без excludeLast (default) клонирует сообщения, а не ссылается на те же объекты.
     */
    public function testCopyClonesMessages(): void
    {
        $from = new InMemoryChatHistory();
        $original = new Message(MessageRole::USER, 'clone-me');
        $from->addMessage($original);

        $to = new InMemoryChatHistory();
        ChatHistoryCopyHelper::copy($from, $to);

        $copied = $to->getMessages();
        $this->assertCount(1, $copied);
        $this->assertNotSame($original, $copied[0]);
        $this->assertSame('clone-me', (string) $copied[0]->getContent());
    }

    /**
     * Добавляет сообщения с чередованием user/assistant — валидная последовательность для InMemoryChatHistory.
     *
     * @param InMemoryChatHistory $history  Целевая история.
     * @param list<string>        $contents Тексты сообщений.
     */
    private function addAlternatingMessages(InMemoryChatHistory $history, array $contents): void
    {
        foreach ($contents as $i => $text) {
            $role = $i % 2 === 0 ? MessageRole::USER : MessageRole::ASSISTANT;
            $history->addMessage(new Message($role, $text));
        }
    }
}
