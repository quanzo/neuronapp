<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\enums\LlmCyclePollStatus;
use app\modules\neuron\helpers\LlmCycleHelper;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use PHPUnit\Framework\TestCase;

/**
 * Тесты классификации ответа проверки статуса {@see LlmCyclePollStatus::fromAgentAnswer}.
 *
 * Тестируемая сущность: {@see \app\modules\neuron\enums\LlmCyclePollStatus}
 */
final class LlmCycleHelperTest extends TestCase
{
    private static function classify(mixed $answer): LlmCyclePollStatus
    {
        return LlmCyclePollStatus::fromAgentAnswer($answer);
    }

    /**
     * DTO (не сообщение) — как раньше трактуем как успешное завершение опроса.
     */
    public function testCheckEndCycleObjectReturnsCompleted(): void
    {
        $this->assertSame(
            LlmCyclePollStatus::Completed,
            self::classify((object) ['x' => 1])
        );
    }

    /**
     * null — невнятный ответ.
     */
    public function testCheckEndCycleNullReturnsUnclear(): void
    {
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify(null));
    }

    /**
     * false — невнятный ответ.
     */
    public function testCheckEndCycleFalseReturnsUnclear(): void
    {
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify(false));
    }

    /**
     * Пустой текст ассистента — невнятно.
     */
    public function testCheckEndCycleEmptyAssistantTextReturnsUnclear(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, '');
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify($msg));
    }

    /**
     * Только пробелы — невнятно.
     */
    public function testCheckEndCycleWhitespaceOnlyReturnsUnclear(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, "  \n  ");
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify($msg));
    }

    /**
     * Первая значимая строка YES — завершено.
     */
    public function testCheckEndCycleYesLineReturnsCompleted(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'YES');
        $this->assertSame(LlmCyclePollStatus::Completed, self::classify($msg));
    }

    /**
     * YES с пояснением в той же строке — завершено (ключевое слово в начале).
     */
    public function testCheckEndCycleYesWithSuffixReturnsCompleted(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'YES, task done.');
        $this->assertSame(LlmCyclePollStatus::Completed, self::classify($msg));
    }

    /**
     * Пустые строки, затем YES — завершено.
     */
    public function testCheckEndCycleYesAfterBlankLinesReturnsCompleted(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, "\n\nYES\n");
        $this->assertSame(LlmCyclePollStatus::Completed, self::classify($msg));
    }

    /**
     * NO — явно в работе.
     */
    public function testCheckEndCycleNoReturnsInProgress(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'NO');
        $this->assertSame(LlmCyclePollStatus::InProgress, self::classify($msg));
    }

    /**
     * WAITING — подтверждение завершения (как «YES, но жду»).
     */
    public function testCheckEndCycleWaitingReturnsInProgress(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'WAITING');
        $this->assertSame(LlmCyclePollStatus::Completed, self::classify($msg));
    }

    /**
     * Текст без ключевого слова в начале первой строки — невнятно.
     */
    public function testCheckEndCycleProseWithoutKeywordReturnsUnclear(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'I will continue processing.');
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify($msg));
    }

    /**
     * JSON вместо YES/NO — первая строка не начинается с ключевого слова — невнятно.
     */
    public function testCheckEndCycleJsonBlobReturnsUnclear(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, '{"action":"get","success":true}');
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify($msg));
    }

    /**
     * ToolCallMessage (ответ инструментами) — невнятно для опроса, даже при пустом списке tools.
     */
    public function testCheckEndCycleToolCallMessageReturnsUnclear(): void
    {
        $msg = new ToolCallMessage(null, []);
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify($msg));
    }

    /**
     * ToolCallMessage с текстом YES в теле — всё равно невнятно: приоритет у типа tool-call.
     */
    public function testCheckEndCycleToolCallMessageWithYesTextStillUnclear(): void
    {
        $msg = new ToolCallMessage('YES', []);
        $this->assertSame(LlmCyclePollStatus::Unclear, self::classify($msg));
    }

    /**
     * Служебный вопрос цикла — user с точным текстом проверки.
     */
    public function testIsCycleRequestMsgDetectsStatusCheckQuestion(): void
    {
        $msg = new Message(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK);
        $this->assertTrue(LlmCycleHelper::isCycleRequestMsg($msg));
    }

    /**
     * Другие user-сообщения не должны считаться служебным вопросом цикла.
     */
    public function testIsCycleRequestMsgRejectsNonMatchingUserText(): void
    {
        $msg = new Message(MessageRole::USER, 'some other question');
        $this->assertFalse(LlmCycleHelper::isCycleRequestMsg($msg));
    }

    /**
     * Служебный ответ цикла — assistant с явным статусом YES/NO/WAITING.
     */
    public function testIsCycleResponseMsgDetectsExplicitStatusAnswer(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'NO — still working');
        $this->assertTrue(LlmCycleHelper::isCycleResponseMsg($msg));
    }

    /**
     * Ответ «чем-то своим» не является служебным ответом и должен оставаться в истории.
     */
    public function testIsCycleResponseMsgRejectsAmbiguousAssistantText(): void
    {
        $msg = new Message(MessageRole::ASSISTANT, 'maybe, I need to think');
        $this->assertFalse(LlmCycleHelper::isCycleResponseMsg($msg));
    }

    /**
     * ToolCallMessage не считаем служебным ответом цикла (не удаляем).
     */
    public function testIsCycleResponseMsgRejectsToolCallMessage(): void
    {
        $msg = new ToolCallMessage('NO', []);
        $this->assertFalse(LlmCycleHelper::isCycleResponseMsg($msg));
    }
}
