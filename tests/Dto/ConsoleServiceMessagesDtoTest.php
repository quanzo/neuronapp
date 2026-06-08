<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\dto\console\ConsoleServiceMessageDto;
use app\modules\neuron\classes\dto\console\ConsoleServiceMessagesDto;
use app\modules\neuron\enums\ConsoleServiceMessageLevel;
use PHPUnit\Framework\TestCase;

/**
 * Тесты {@see ConsoleServiceMessagesDto} и {@see ConsoleServiceMessageDto}.
 */
final class ConsoleServiceMessagesDtoTest extends TestCase
{
    /**
     * Новая коллекция пуста.
     */
    public function testNewCollectionIsEmpty(): void
    {
        $dto = new ConsoleServiceMessagesDto();

        $this->assertTrue($dto->isEmpty());
        $this->assertSame([], $dto->getAll());
    }

    /**
     * addPlain добавляет plain-сообщение.
     */
    public function testAddPlainStoresMessage(): void
    {
        $dto = (new ConsoleServiceMessagesDto())->addPlain('текст');

        $this->assertFalse($dto->isEmpty());
        $this->assertCount(1, $dto->getAll());
        $this->assertSame('текст', $dto->getAll()[0]->getText());
        $this->assertSame(ConsoleServiceMessageLevel::Plain, $dto->getAll()[0]->getLevel());
    }

    /**
     * addInfo добавляет info-сообщение.
     */
    public function testAddInfoStoresInfoLevel(): void
    {
        $dto = (new ConsoleServiceMessagesDto())->addInfo('info line');

        $this->assertSame(ConsoleServiceMessageLevel::Info, $dto->getAll()[0]->getLevel());
    }

    /**
     * addComment добавляет comment-сообщение.
     */
    public function testAddCommentStoresCommentLevel(): void
    {
        $dto = (new ConsoleServiceMessagesDto())->addComment('comment line');

        $this->assertSame(ConsoleServiceMessageLevel::Comment, $dto->getAll()[0]->getLevel());
    }

    /**
     * add принимает готовый ConsoleServiceMessageDto.
     */
    public function testAddAcceptsMessageDto(): void
    {
        $dto = (new ConsoleServiceMessagesDto())->add(ConsoleServiceMessageDto::plain('x'));

        $this->assertSame('x', $dto->getAll()[0]->getText());
    }

    /**
     * Сообщения сохраняют порядок добавления.
     */
    public function testMessagesPreserveOrder(): void
    {
        $dto = (new ConsoleServiceMessagesDto())
            ->addPlain('first')
            ->addInfo('second')
            ->addComment('third');

        $this->assertSame(['first', 'second', 'third'], array_map(
            static fn (ConsoleServiceMessageDto $m): string => $m->getText(),
            $dto->getAll(),
        ));
    }

    /**
     * merge добавляет сообщения другой коллекции в конец.
     */
    public function testMergeAppendsOtherMessages(): void
    {
        $a = (new ConsoleServiceMessagesDto())->addPlain('a');
        $b = (new ConsoleServiceMessagesDto())->addPlain('b');

        $a->merge($b);

        $this->assertSame(['a', 'b'], array_map(
            static fn (ConsoleServiceMessageDto $m): string => $m->getText(),
            $a->getAll(),
        ));
    }

    /**
     * merge пустой коллекции не меняет целевую.
     */
    public function testMergeEmptyDoesNotChangeTarget(): void
    {
        $dto = (new ConsoleServiceMessagesDto())->addPlain('only');
        $dto->merge(new ConsoleServiceMessagesDto());

        $this->assertCount(1, $dto->getAll());
    }

    /**
     * toArray сериализует text и level.
     */
    public function testToArraySerializesMessages(): void
    {
        $arr = (new ConsoleServiceMessagesDto())
            ->addInfo('Summary обновлён')
            ->toArray();

        $this->assertSame([
            ['text' => 'Summary обновлён', 'level' => 'info'],
        ], $arr);
    }

    /**
     * ConsoleServiceMessageDto::toArray для plain.
     */
    public function testMessageDtoToArrayPlain(): void
    {
        $arr = ConsoleServiceMessageDto::plain('line')->toArray();

        $this->assertSame(['text' => 'line', 'level' => 'plain'], $arr);
    }

    /**
     * Пустая коллекция toArray — пустой список.
     */
    public function testEmptyCollectionToArray(): void
    {
        $this->assertSame([], (new ConsoleServiceMessagesDto())->toArray());
    }

    /**
     * merge не делает shallow copy проблем: исходные DTO независимы.
     */
    public function testMergeDoesNotAffectSourceAfterMerge(): void
    {
        $source = (new ConsoleServiceMessagesDto())->addPlain('src');
        $target = new ConsoleServiceMessagesDto();
        $target->merge($source);
        $source->addPlain('extra');

        $this->assertCount(2, $source->getAll());
        $this->assertCount(1, $target->getAll());
    }
}
