<?php

declare(strict_types=1);

namespace Tests\Helpers;

use app\modules\neuron\classes\dto\console\ConsoleServiceMessagesDto;
use app\modules\neuron\classes\dto\console\OutputDto;
use app\modules\neuron\classes\dto\console\HrtimeDto;
use app\modules\neuron\classes\dto\orchestrator\OrchestratorResultDto;
use app\modules\neuron\helpers\ConsoleHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Тесты {@see ConsoleHelper} — форматирование и запись OutputDto.
 */
final class ConsoleHelperTest extends TestCase
{
    /**
     * resolveFormat нормализует пустое значение в default.
     */
    public function testResolveFormatUsesDefaultForEmpty(): void
    {
        $this->assertSame('md', ConsoleHelper::resolveFormat(null, 'md'));
        $this->assertSame('json', ConsoleHelper::resolveFormat('', 'json'));
    }

    /**
     * resolveFormat принимает md, txt, json.
     */
    public function testResolveFormatAcceptsKnownFormats(): void
    {
        $this->assertSame('txt', ConsoleHelper::resolveFormat('txt', 'md'));
        $this->assertSame('json', ConsoleHelper::resolveFormat('json', 'md'));
    }

    /**
     * resolveFormat при неверном формате возвращает OutputDto с ошибкой.
     */
    public function testResolveFormatReturnsErrorDtoForInvalid(): void
    {
        $result = ConsoleHelper::resolveFormat('xml', 'md');

        $this->assertInstanceOf(OutputDto::class, $result);
        $this->assertTrue($result->isError());
    }

    /**
     * formatOut md: успех — response и sessionKey.
     */
    public function testFormatOutMdSuccess(): void
    {
        $text = ConsoleHelper::formatOut(OutputDto::fromResponse('hello', 'sess'), 'md');

        $this->assertStringContainsString('hello', $text);
        $this->assertStringContainsString('sessionKey=sess', $text);
    }

    /**
     * formatOut md: ошибка — тег error и sessionKey.
     */
    public function testFormatOutMdError(): void
    {
        $text = ConsoleHelper::formatOut(OutputDto::fromError('fail msg', 's1'), 'md');

        $this->assertStringContainsString('<error>fail msg</error>', $text);
        $this->assertStringContainsString('sessionKey=s1', $text);
    }

    /**
     * formatOut txt ведёт себя как md.
     */
    public function testFormatOutTxtSameAsMd(): void
    {
        $dto = OutputDto::fromResponse('x', 'y');
        $md = ConsoleHelper::formatOut($dto, 'md');
        $txt = ConsoleHelper::formatOut($dto, 'txt');

        $this->assertSame($md, $txt);
    }

    /**
     * formatOut json — валидный JSON с полями DTO.
     */
    public function testFormatOutJsonEncodesDto(): void
    {
        $json = ConsoleHelper::formatOut(OutputDto::fromResponse('r', 'k'), 'json');
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('r', $decoded['response']);
        $this->assertSame('k', $decoded['sessionKey']);
    }

    /**
     * writeResult возвращает SUCCESS для успешного DTO.
     */
    public function testWriteResultSuccessExitCode(): void
    {
        $output = new BufferedOutput();
        $code = ConsoleHelper::writeResult($output, OutputDto::fromResponse('ok', 'k'), 'json');

        $this->assertSame(Command::SUCCESS, $code);
    }

    /**
     * writeResult возвращает FAILURE при errorMessage.
     */
    public function testWriteResultFailureOnErrorDto(): void
    {
        $output = new BufferedOutput();
        $code = ConsoleHelper::writeResult($output, OutputDto::fromError('err'), 'json');

        $this->assertSame(Command::FAILURE, $code);
    }

    /**
     * writeResult возвращает FAILURE при orchestrator.success === false.
     */
    public function testWriteResultFailureOnOrchestratorBusinessFail(): void
    {
        $orch = (new OrchestratorResultDto())
            ->setSuccess(false)
            ->setReason('max_iterations')
            ->setSessionKey('k');

        $output = new BufferedOutput();
        $code = ConsoleHelper::writeResult($output, OutputDto::fromOrchestrator($orch), 'json');

        $this->assertSame(Command::FAILURE, $code);
    }

    /**
     * writeResult пишет строку в output.
     */
    public function testWriteResultWritesToOutput(): void
    {
        $output = new BufferedOutput();
        ConsoleHelper::writeResult($output, OutputDto::fromResponse('data', 'sk'), 'md');

        $this->assertStringContainsString('data', $output->fetch());
    }

    /**
     * formatOut md: serviceMessages info/comment перед response.
     */
    public function testFormatOutMdIncludesServiceMessagesWithTags(): void
    {
        $service = (new ConsoleServiceMessagesDto())
            ->addInfo('Summary обновлён')
            ->addComment('без изменений');
        $dto = OutputDto::fromResponse('текст summary', 'sk')->withServiceMessages($service);

        $text = ConsoleHelper::formatOut($dto, 'md');

        $this->assertStringContainsString('<info>Summary обновлён</info>', $text);
        $this->assertStringContainsString('<comment>без изменений</comment>', $text);
        $this->assertStringContainsString('текст summary', $text);
        $this->assertStringContainsString('sessionKey=sk', $text);
    }

    /**
     * formatOut json: serviceMessages внутри одного JSON-объекта.
     */
    public function testFormatOutJsonIncludesServiceMessages(): void
    {
        $service = (new ConsoleServiceMessagesDto())->addPlain('статус убран');
        $dto = OutputDto::fromResponse('ответ', 'k')->withServiceMessages($service);

        $decoded = json_decode(ConsoleHelper::formatOut($dto, 'json'), true);

        $this->assertIsArray($decoded);
        $this->assertSame('ответ', $decoded['response']);
        $this->assertSame([['text' => 'статус убран', 'level' => 'plain']], $decoded['serviceMessages']);
    }

    /**
     * writeResult json: stdout — одна строка валидного JSON.
     */
    public function testWriteResultJsonSingleLineOutput(): void
    {
        $service = (new ConsoleServiceMessagesDto())->addPlain('msg');
        $dto = OutputDto::fromResponse('body', 'k')->withServiceMessages($service);
        $output = new BufferedOutput();

        ConsoleHelper::writeResult($output, $dto, 'json');
        $lines = array_filter(explode("\n", trim($output->fetch())));

        $this->assertCount(1, $lines);
        $this->assertIsArray(json_decode($lines[0], true));
    }

    /**
     * formatOut json включает поля command timing.
     */
    public function testFormatOutJsonIncludesCommandTiming(): void
    {
        $dto = OutputDto::fromResponse('r', 'k')->withCommandTiming(
            HrtimeDto::fromNanoseconds(0.0),
            HrtimeDto::fromNanoseconds(5_000_000_000.0),
        );

        $decoded = json_decode(ConsoleHelper::formatOut($dto, 'json'), true);

        $this->assertIsArray($decoded);
        $this->assertEqualsWithDelta(0.0, $decoded['startedAt'], 0.001);
        $this->assertEqualsWithDelta(5_000_000_000.0, $decoded['endedAt'], 0.001);
        $this->assertEqualsWithDelta(5.0, $decoded['durationSeconds'], 0.001);
    }

    /**
     * formatOut md/txt выводит строки timing после sessionKey.
     */
    public function testFormatOutMdIncludesCommandTimingFooter(): void
    {
        $dto = OutputDto::fromResponse('hello', 'sess')->withCommandTiming(
            HrtimeDto::fromNanoseconds(100.0),
            HrtimeDto::fromNanoseconds(1_500_000_100.0),
        );

        $text = ConsoleHelper::formatOut($dto, 'md');

        $this->assertStringContainsString('sessionKey=sess', $text);
        $this->assertStringContainsString('startedAt=100', $text);
        $this->assertStringContainsString('endedAt=1500000100', $text);
        $this->assertStringContainsString('durationSeconds=1.5', $text);
    }

    /**
     * formatOut md без timing не добавляет строки started/ended/duration.
     */
    public function testFormatOutMdWithoutTimingOmitsFooter(): void
    {
        $text = ConsoleHelper::formatOut(OutputDto::fromResponse('x', 'y'), 'md');

        $this->assertStringNotContainsString('startedAt=', $text);
        $this->assertStringNotContainsString('endedAt=', $text);
        $this->assertStringNotContainsString('durationSeconds=', $text);
    }
}
