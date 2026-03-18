<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\neuron\trimmers;

use NeuronAI\Chat\History\TokenCounter as NeuronAiTokenCounter;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

/**
 * Считалка токенов
 */
class TokenCounter extends NeuronAiTokenCounter
{
    /**
     * Кол-во символов в текстовом блоке
     *
     * @param TextContent $block
     * @return integer
     */
    protected function handleTextBlock(TextContent $block): int
    {
        /**
         * Стандартная переключалка символы юникода превращает в \u0438 и размер текстового блока становится нереальным
         */
        $txt = preg_replace(['/\s+/is'], [' '], json_encode($block->toArray(), JSON_UNESCAPED_UNICODE));
        return mb_strlen($txt);
    }
}
