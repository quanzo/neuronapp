<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\neuron\classes\config\ConfigurationAgent;
use app\modules\neuron\classes\config\ConfigurationApp;
use app\modules\neuron\classes\dir\DirPriority;
use app\modules\neuron\classes\dto\console\ConsoleServiceMessagesDto;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\classes\dto\console\HrtimeDto;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\classes\neuron\history\InMemoryFullChatHistory;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Тесты {@see OutputDto} — унифицированный вывод LLM-команд.
 */
final class OutputDtoTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/neuronapp_output_dto_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        mkdir($this->tmpDir . '/.sessions', 0777, true);
        $this->resetConfigurationApp();
        ConfigurationApp::init(new DirPriority([$this->tmpDir]));
    }

    protected function tearDown(): void
    {
        $this->resetConfigurationApp();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * fromError создаёт DTO с errorMessage и isError() === true.
     */
    public function testFromErrorSetsErrorMessage(): void
    {
        $dto = OutputDto::fromError('Ошибка валидации', 'sess-1');

        $this->assertTrue($dto->isError());
        $this->assertSame('Ошибка валидации', $dto->getErrorMessage());
        $this->assertSame('', $dto->getResponse());
        $this->assertSame('sess-1', $dto->getSessionKey());
    }

    /**
     * fromError без sessionKey — пустой ключ сессии.
     */
    public function testFromErrorWithoutSessionKey(): void
    {
        $dto = OutputDto::fromError('fail');

        $this->assertTrue($dto->isError());
        $this->assertSame('', $dto->getSessionKey());
    }

    /**
     * fromResponse создаёт успешный DTO с текстом ответа.
     */
    public function testFromResponseSetsResponse(): void
    {
        $dto = OutputDto::fromResponse('Ответ LLM', '20250301-143022-1-0');

        $this->assertFalse($dto->isError());
        $this->assertSame('Ответ LLM', $dto->getResponse());
        $this->assertSame('20250301-143022-1-0', $dto->getSessionKey());
    }

    /**
     * fromAgent при пустой истории возвращает ошибку «Нет ответа».
     */
    public function testFromAgentEmptyHistoryReturnsError(): void
    {
        $agent = $this->makeAgentWithHistory([]);

        $dto = OutputDto::fromAgent($agent);

        $this->assertTrue($dto->isError());
        $this->assertStringContainsString('Нет ответа', $dto->getErrorMessage());
    }

    /**
     * fromAgent берёт контент последнего сообщения истории.
     */
    public function testFromAgentUsesLastMessageContent(): void
    {
        $agent = $this->makeAgentWithHistory([
            new Message(MessageRole::USER, 'вопрос'),
            new Message(MessageRole::ASSISTANT, 'ответ ассистента'),
        ]);

        $dto = OutputDto::fromAgent($agent);

        $this->assertFalse($dto->isError());
        $this->assertSame('ответ ассистента', $dto->getResponse());
    }

    /**
     * fromException переносит сообщение исключения в errorMessage.
     */
    public function testFromExceptionWrapsThrowable(): void
    {
        $agent = $this->makeAgentWithHistory([]);
        $agent->setSessionKey('exc-session');

        $dto = OutputDto::fromException(new RuntimeException('LLM timeout'), $agent);

        $this->assertTrue($dto->isError());
        $this->assertSame('LLM timeout', $dto->getErrorMessage());
        $this->assertSame('exc-session', $dto->getSessionKey());
    }

    /**
     * fromExceptionWithSessionKey работает без ConfigurationAgent.
     */
    public function testFromExceptionWithSessionKey(): void
    {
        $dto = OutputDto::fromExceptionWithSessionKey(new RuntimeException('boom'), 'sk');

        $this->assertTrue($dto->isError());
        $this->assertSame('boom', $dto->getErrorMessage());
        $this->assertSame('sk', $dto->getSessionKey());
    }

    /**
     * fromOrchestrator заполняет response, sessionKey и вложенный orchestrator.
     */
    public function testFromOrchestratorIncludesNestedResult(): void
    {
        $history = new InMemoryFullChatHistory(contextWindow: 1000);
        $history->addMessage(new Message(MessageRole::ASSISTANT, 'finish text'));

        $orch = (new OrchestratorResultDto())
            ->setSuccess(true)
            ->setReason('completed')
            ->setIterations(5)
            ->setSessionKey('orch-sess');
        $orch->setMessage($history);

        $dto = OutputDto::fromOrchestrator($orch);

        $this->assertFalse($dto->isError());
        $this->assertSame('finish text', $dto->getResponse());
        $this->assertSame('orch-sess', $dto->getSessionKey());
        $this->assertNotNull($dto->getOrchestrator());
        $this->assertTrue($dto->getOrchestrator()->isSuccess());
    }

    /**
     * toArray без orchestrator — только response и sessionKey.
     */
    public function testToArraySuccessWithoutOrchestrator(): void
    {
        $arr = OutputDto::fromResponse('ok', 'k1')->toArray();

        $this->assertSame(['response' => 'ok', 'sessionKey' => 'k1'], $arr);
        $this->assertArrayNotHasKey('errorMessage', $arr);
        $this->assertArrayNotHasKey('orchestrator', $arr);
    }

    /**
     * toArray с ошибкой включает errorMessage.
     */
    public function testToArrayIncludesErrorMessageWhenPresent(): void
    {
        $arr = OutputDto::fromError('bad', 'k2')->toArray();

        $this->assertSame('bad', $arr['errorMessage']);
        $this->assertSame('', $arr['response']);
    }

    /**
     * toArray с orchestrator включает вложенный блок.
     */
    public function testToArrayIncludesOrchestratorBlock(): void
    {
        $orch = (new OrchestratorResultDto())
            ->setSuccess(false)
            ->setReason('max_iterations')
            ->setSessionKey('k3');

        $dto = OutputDto::fromOrchestrator($orch);
        $arr = $dto->toArray();

        $this->assertArrayHasKey('orchestrator', $arr);
        $this->assertFalse($arr['orchestrator']['success']);
        $this->assertSame('max_iterations', $arr['orchestrator']['reason']);
    }

    /**
     * setOrchestrator fluent setter сохраняет ссылку.
     */
    public function testSetOrchestratorFluent(): void
    {
        $orch = (new OrchestratorResultDto())->setSuccess(true);
        $dto = (new OutputDto())->setOrchestrator($orch);

        $this->assertSame($orch, $dto->getOrchestrator());
    }

    /**
     * fromAgentNotFound формирует стандартное сообщение об ошибке.
     */
    public function testFromAgentNotFoundMessage(): void
    {
        $dto = OutputDto::fromAgentNotFound('missing', 'sk-1');

        $this->assertTrue($dto->isError());
        $this->assertSame('Агент "missing" не найден.', $dto->getErrorMessage());
        $this->assertSame('sk-1', $dto->getSessionKey());
    }

    /**
     * fromInvalidSessionKey использует describeSessionKeyFormat.
     */
    public function testFromInvalidSessionKeyMessage(): void
    {
        $dto = OutputDto::fromInvalidSessionKey('bad-key');

        $this->assertTrue($dto->isError());
        $this->assertStringContainsString('Неверный формат session_id', $dto->getErrorMessage());
        $this->assertStringContainsString(ConfigurationApp::describeSessionKeyFormat(), $dto->getErrorMessage());
    }

    /**
     * fromUnfinishedRun с подсказкой resume/abort.
     */
    public function testFromUnfinishedRunWithResumeHint(): void
    {
        $dto = OutputDto::fromUnfinishedRun('list1', 'sk', true);

        $this->assertStringContainsString('--resume', $dto->getErrorMessage());
        $this->assertStringContainsString('list1', $dto->getErrorMessage());
    }

    /**
     * withServiceMessages подмешивает сервисные сообщения в toArray.
     */
    public function testWithServiceMessagesInToArray(): void
    {
        $service = (new ConsoleServiceMessagesDto())->addPlain('side effect');
        $dto = OutputDto::fromResponse('answer', 'k')->withServiceMessages($service);
        $arr = $dto->toArray();

        $this->assertArrayHasKey('serviceMessages', $arr);
        $this->assertSame([['text' => 'side effect', 'level' => 'plain']], $arr['serviceMessages']);
        $this->assertSame('answer', $arr['response']);
    }

    /**
     * addServiceInfo на OutputDto добавляет info-сообщение.
     */
    public function testAddServiceInfoOnOutputDto(): void
    {
        $dto = OutputDto::fromResponse('x', 'k')->addServiceInfo('meta');

        $this->assertFalse($dto->getServiceMessages()->isEmpty());
        $this->assertSame('meta', $dto->getServiceMessages()->getAll()[0]->getText());
    }

    /**
     * withCommandTiming добавляет поля timing в toArray.
     */
    public function testWithCommandTimingInToArray(): void
    {
        $started = HrtimeDto::fromNanoseconds(0.0);
        $ended = HrtimeDto::fromNanoseconds(2_500_000_000.0);
        $arr = OutputDto::fromResponse('ok', 'k')->withCommandTiming($started, $ended)->toArray();

        $this->assertSame(0.0, $arr['startedAt']);
        $this->assertSame(2_500_000_000.0, $arr['endedAt']);
        $this->assertSame(2.5, $arr['durationSeconds']);
    }

    /**
     * Без command timing ключи timing отсутствуют в toArray.
     */
    public function testToArrayWithoutCommandTimingOmitsKeys(): void
    {
        $arr = OutputDto::fromResponse('ok', 'k')->toArray();

        $this->assertArrayNotHasKey('startedAt', $arr);
        $this->assertArrayNotHasKey('endedAt', $arr);
        $this->assertArrayNotHasKey('durationSeconds', $arr);
    }

    /**
     * withServiceMessages сохраняет command timing в копии DTO.
     */
    public function testWithServiceMessagesPreservesCommandTiming(): void
    {
        $started = HrtimeDto::fromNanoseconds(0.0);
        $ended = HrtimeDto::fromNanoseconds(1_000_000_000.0);
        $service = (new ConsoleServiceMessagesDto())->addPlain('note');
        $dto = OutputDto::fromResponse('r', 'k')
            ->withCommandTiming($started, $ended)
            ->withServiceMessages($service);

        $this->assertSame($started, $dto->getStartedAt());
        $this->assertSame($ended, $dto->getEndedAt());
        $this->assertSame(1.0, $dto->toArray()['durationSeconds']);
        $this->assertArrayHasKey('serviceMessages', $dto->toArray());
    }

    /**
     * getStartedAt/getEndedAt возвращают null для DTO без замера.
     */
    public function testGetTimingNullByDefault(): void
    {
        $dto = OutputDto::fromResponse('x', 'k');

        $this->assertNull($dto->getStartedAt());
        $this->assertNull($dto->getEndedAt());
        $this->assertNull($dto->getDurationSeconds());
    }

    /**
     * @param list<Message> $messages
     */
    private function makeAgentWithHistory(array $messages): ConfigurationAgent
    {
        $agent = ConfigurationAgent::makeFromArray(['contextWindow' => 4000], ConfigurationApp::getInstance());
        $this->assertNotNull($agent);

        $history = new InMemoryFullChatHistory(contextWindow: 4000);
        foreach ($messages as $message) {
            $history->addMessage($message);
        }
        $agent->setSessionKey('agent-sess');
        $agent->setChatHistory($history);

        return $agent;
    }

    private function resetConfigurationApp(): void
    {
        $ref = new \ReflectionClass(ConfigurationApp::class);
        $ref->getProperty('instance')->setValue(null, null);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
