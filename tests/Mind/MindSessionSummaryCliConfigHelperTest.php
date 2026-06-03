<?php

declare(strict_types=1);

namespace Tests\Mind;

use app\modules\neuron\mind\helpers\MindSessionSummaryCliConfigHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Тесты {@see MindSessionSummaryCliConfigHelper}: сборка DTO из опций CLI.
 */
final class MindSessionSummaryCliConfigHelperTest extends TestCase
{
    /**
     * Только agent — остальные поля null.
     */
    public function testFromInputAgentOnly(): void
    {
        $input = $this->createInput([]);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'my_summarizer_agent');

        $this->assertSame('my_summarizer_agent', $dto->getAgent());
        $this->assertNull($dto->getMaxSummaryChars());
        $this->assertNull($dto->getTranscriptRatio());
        $this->assertSame(300, $dto->resolveMaxSummaryChars());
        $this->assertSame(0.25, $dto->resolveTranscriptRatio());
    }

    /**
     * max_summary_chars из CLI попадает в DTO.
     */
    public function testFromInputWithMaxSummaryChars(): void
    {
        $input = $this->createInput(['max-summary-chars' => '400']);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'a');

        $this->assertSame(400, $dto->getMaxSummaryChars());
        $this->assertSame(400, $dto->resolveMaxSummaryChars());
    }

    /**
     * transcript_ratio из CLI попадает в DTO.
     */
    public function testFromInputWithTranscriptRatio(): void
    {
        $input = $this->createInput(['transcript-ratio' => '0.3']);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'a');

        $this->assertSame(0.3, $dto->getTranscriptRatio());
        $this->assertSame(0.3, $dto->resolveTranscriptRatio());
    }

    /**
     * Обе опции summary заданы.
     */
    public function testFromInputWithBothSummaryOptions(): void
    {
        $input = $this->createInput([
            'max-summary-chars' => '120',
            'transcript-ratio' => '0.2',
        ]);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'summ');

        $this->assertSame(120, $dto->getMaxSummaryChars());
        $this->assertSame(0.2, $dto->getTranscriptRatio());
    }

    /**
     * max-summary-chars ниже 50 — исключение.
     */
    public function testFromInputRejectsMaxSummaryCharsBelowMinimum(): void
    {
        $input = $this->createInput(['max-summary-chars' => '49']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max-summary-chars');

        MindSessionSummaryCliConfigHelper::fromInput($input, 'a');
    }

    /**
     * max-summary-chars не число — исключение.
     */
    public function testFromInputRejectsNonNumericMaxSummaryChars(): void
    {
        $input = $this->createInput(['max-summary-chars' => 'abc']);
        $this->expectException(\InvalidArgumentException::class);

        MindSessionSummaryCliConfigHelper::fromInput($input, 'a');
    }

    /**
     * transcript-ratio ниже 0.05 — исключение.
     */
    public function testFromInputRejectsTranscriptRatioTooLow(): void
    {
        $input = $this->createInput(['transcript-ratio' => '0.01']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transcript-ratio');

        MindSessionSummaryCliConfigHelper::fromInput($input, 'a');
    }

    /**
     * transcript-ratio выше 0.5 — исключение.
     */
    public function testFromInputRejectsTranscriptRatioTooHigh(): void
    {
        $input = $this->createInput(['transcript-ratio' => '0.9']);
        $this->expectException(\InvalidArgumentException::class);

        MindSessionSummaryCliConfigHelper::fromInput($input, 'a');
    }

    /**
     * transcript-ratio не число — исключение.
     */
    public function testFromInputRejectsNonNumericTranscriptRatio(): void
    {
        $input = $this->createInput(['transcript-ratio' => 'high']);
        $this->expectException(\InvalidArgumentException::class);

        MindSessionSummaryCliConfigHelper::fromInput($input, 'a');
    }

    /**
     * Граница max-summary-chars = 50 допустима.
     */
    public function testFromInputAcceptsMaxSummaryCharsAtMinimum(): void
    {
        $input = $this->createInput(['max-summary-chars' => '50']);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'a');

        $this->assertSame(50, $dto->resolveMaxSummaryChars());
    }

    /**
     * Граница transcript-ratio = 0.05 допустима.
     */
    public function testFromInputAcceptsTranscriptRatioAtMinimum(): void
    {
        $input = $this->createInput(['transcript-ratio' => '0.05']);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'a');

        $this->assertSame(0.05, $dto->resolveTranscriptRatio());
    }

    /**
     * Пустые строки опций summary игнорируются (дефолты resolve).
     */
    public function testFromInputIgnoresEmptyOptionalStrings(): void
    {
        $input = $this->createInput([
            'max-summary-chars' => '',
            'transcript-ratio' => '',
        ]);
        $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'a');

        $this->assertNull($dto->getMaxSummaryChars());
        $this->assertNull($dto->getTranscriptRatio());
    }

    /**
     * @param array<string, string> $options
     */
    private function createInput(array $options): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('max-summary-chars', null, InputOption::VALUE_OPTIONAL),
            new InputOption('transcript-ratio', null, InputOption::VALUE_OPTIONAL),
        ]);

        $normalized = [];
        foreach ($options as $key => $value) {
            $normalized[str_starts_with((string) $key, '--') ? $key : '--' . $key] = $value;
        }

        return new ArrayInput($normalized, $definition);
    }
}
