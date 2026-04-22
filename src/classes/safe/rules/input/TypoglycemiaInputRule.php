<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\rules\input;

use app\modules\neuron\classes\safe\contracts\InputDetectorRuleInterface;
use app\modules\neuron\classes\safe\dto\InputViolationDto;

/**
 * Детектирует типогликимию для ключевых слов jailbreak/prompt-injection.
 * 
 * Typoglycemia — это когнитивное явление, при котором человек способен понимать текст, даже если внутри слов перемешаны буквы, но при этом первая и последняя буквы остаются на своих местах.
 */
class TypoglycemiaInputRule implements InputDetectorRuleInterface
{
    /**
     * @param list<string> $dangerWords Ключевые слова для fuzzy-детекции.
     */
    public function __construct(private readonly array $dangerWords = [
        'ignore',
        'bypass',
        'override',
        'reveal',
        'system',
        'prompt',
        'instruction',
        'developer',
    ])
    {
    }

    /**
     * @inheritDoc
     */
    public function detect(string $text): ?InputViolationDto
    {
        preg_match_all('/\b[\p{L}]{4,}\b/u', mb_strtolower($text), $matches);
        $words = $matches[0] ?? [];

        foreach ($words as $word) {
            if (!is_string($word)) {
                continue;
            }

            foreach ($this->dangerWords as $dangerWord) {
                if ($this->isTypoglycemiaVariant($word, $dangerWord)) {
                    return (new InputViolationDto())
                        ->setCode('typoglycemia_injection')
                        ->setReason('Input contains obfuscated jailbreak keywords.')
                        ->setMatchedFragment($word);
                }
            }
        }

        return null;
    }

    /**
     * Определяет, является ли слово перестановкой middle-символов target-слова.
     */
    private function isTypoglycemiaVariant(string $word, string $target): bool
    {
        if ($word === $target) {
            return false;
        }

        if (mb_strlen($word) !== mb_strlen($target) || mb_strlen($word) < 4) {
            return false;
        }

        $wordFirst = mb_substr($word, 0, 1);
        $wordLast = mb_substr($word, -1, 1);
        $targetFirst = mb_substr($target, 0, 1);
        $targetLast = mb_substr($target, -1, 1);
        if ($wordFirst !== $targetFirst || $wordLast !== $targetLast) {
            return false;
        }

        $wordMiddle = mb_substr($word, 1, mb_strlen($word) - 2);
        $targetMiddle = mb_substr($target, 1, mb_strlen($target) - 2);

        return $this->sortUtf8Chars($wordMiddle) === $this->sortUtf8Chars($targetMiddle);
    }

    /**
     * Сортирует UTF-8 символы в строке.
     */
    private function sortUtf8Chars(string $text): string
    {
        preg_match_all('/./u', $text, $matches);
        $chars = $matches[0] ?? [];
        sort($chars);

        return implode('', $chars);
    }
}
