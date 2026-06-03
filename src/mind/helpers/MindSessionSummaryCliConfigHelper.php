<?php

declare(strict_types=1);

namespace app\modules\neuron\mind\helpers;

use app\modules\neuron\mind\dto\config\MindSessionSummaryConfigDto;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Сборка {@see MindSessionSummaryConfigDto} из опций CLI `mind:summary`.
 *
 * Пример:
 *
 * <code>
 * $dto = MindSessionSummaryCliConfigHelper::fromInput($input, 'my_summarizer_agent');
 * </code>
 */
final class MindSessionSummaryCliConfigHelper
{
    private const int MIN_MAX_SUMMARY_CHARS = 50;

    private const float MIN_TRANSCRIPT_RATIO = 0.05;

    private const float MAX_TRANSCRIPT_RATIO = 0.5;

    /**
     * Строит DTO из опций команды; незаданные поля остаются null (дефолты при resolve*).
     *
     * @param InputInterface $input     Ввод Symfony Console.
     * @param string         $agentName Имя агента-суммаризатора (--agent).
     *
     * @return MindSessionSummaryConfigDto
     *
     * @throws \InvalidArgumentException При невалидных числовых опциях CLI.
     */
    public static function fromInput(InputInterface $input, string $agentName): MindSessionSummaryConfigDto
    {
        $data = ['agent' => $agentName];

        $maxChars = $input->getOption('max-summary-chars');
        if ($maxChars !== null && $maxChars !== '' && $maxChars !== false) {
            if (!is_numeric($maxChars)) {
                throw new \InvalidArgumentException(
                    'Опция --max-summary-chars должна быть целым числом не меньше '
                    . self::MIN_MAX_SUMMARY_CHARS . '.',
                );
            }
            $maxCharsInt = (int) $maxChars;
            if ($maxCharsInt < self::MIN_MAX_SUMMARY_CHARS) {
                throw new \InvalidArgumentException(
                    'Опция --max-summary-chars должна быть не меньше ' . self::MIN_MAX_SUMMARY_CHARS . '.',
                );
            }
            $data['max_summary_chars'] = $maxCharsInt;
        }

        $ratio = $input->getOption('transcript-ratio');
        if ($ratio !== null && $ratio !== '' && $ratio !== false) {
            if (!is_numeric($ratio)) {
                throw new \InvalidArgumentException(
                    'Опция --transcript-ratio должна быть числом от '
                    . self::MIN_TRANSCRIPT_RATIO . ' до ' . self::MAX_TRANSCRIPT_RATIO . '.',
                );
            }
            $ratioFloat = (float) $ratio;
            if ($ratioFloat < self::MIN_TRANSCRIPT_RATIO || $ratioFloat > self::MAX_TRANSCRIPT_RATIO) {
                throw new \InvalidArgumentException(
                    'Опция --transcript-ratio должна быть в диапазоне '
                    . self::MIN_TRANSCRIPT_RATIO . '–' . self::MAX_TRANSCRIPT_RATIO . '.',
                );
            }
            $data['transcript_ratio'] = $ratioFloat;
        }

        return MindSessionSummaryConfigDto::fromConfigArray($data);
    }
}
