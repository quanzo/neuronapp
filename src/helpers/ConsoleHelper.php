<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

/**
 * Вспомогательные методы для работы с консолью
 */
class ConsoleHelper
{
    /**
     * Отформатировать вывод команды в соответствии с форматом
     *
     * @param mixed $content
     * @param string $sessionId
     * @param string $formatOut
     * @return string
     */
    public static function formatOut(mixed $content, string $sessionId, string $formatOut = 'md'): string
    {
        if (!is_string($content)) {
            $content = (string) $content;
        }

        $out = '';
        switch ($formatOut) {
            case 'md':
                $out = $content . PHP_EOL . PHP_EOL . 'sessionKey=' . $sessionId . PHP_EOL;
                break;
            case 'txt':
                $out = $content . PHP_EOL . PHP_EOL . 'sessionKey=' . $sessionId . PHP_EOL;
                break;
            case 'json':
                $out = json_encode(
                    [
                        'response' => $content,
                        'sessionKey' => $sessionId
                    ],
                    \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
                );
                break;
        }
        return $out;
    }
}
