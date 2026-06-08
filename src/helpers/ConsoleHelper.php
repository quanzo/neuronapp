<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use app\modules\neuron\classes\dto\console\OutputDto;

/**
 * Вспомогательные методы для работы с консолью
 */
class ConsoleHelper
{
    /**
     * Отформатировать вывод команды в соответствии с форматом
     *
     * @param mixed|OutputDto $content
     * @param string $sessionId
     * @param string $formatOut
     * @return string
     */
    public static function formatOut(mixed $content, string $sessionId, string $formatOut = 'md'): string
    {
        $out = '';
        if ($content instanceof OutputDto) {
            switch ($formatOut) {
                case 'md':
                case 'txt':
                    if ($content->isError()) {
                        $out = '<error>' . $content->getErrorMessage() . '</error>';
                    } else {
                        $out = $content->getResponse();
                    }
                    $out .= PHP_EOL . PHP_EOL . 'sessionKey=' . $sessionId . PHP_EOL;
                    break;
                case 'json':
                    $out = JsonHelper::encodeThrow($content->toArray());
                    break;
            }
        } else {
            if (!is_string($content)) {
                $content = (string) $content;
            }
            switch ($formatOut) {
                case 'md':
                case 'txt':
                    $out = $content . PHP_EOL . PHP_EOL . 'sessionKey=' . $sessionId . PHP_EOL;
                    break;
                case 'json':
                    $out = JsonHelper::encodeThrow(
                        [
                            'response' => $content,
                            'sessionKey' => $sessionId
                        ]
                    );
                    break;
            }
        }
        return $out;
    }
}
