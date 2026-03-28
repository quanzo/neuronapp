<?php

declare(strict_types=1);

namespace app\modules\neuron\helpers;

use function array_filter;
use function count;
use function file_get_contents;
use function fnmatch;
use function is_link;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

use app\modules\neuron\classes\config\ConfigurationAgent;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

/**
 * Вспомогательный класс
 */
class LlmCycleHelper
{
    const MSG_CHECK_WORK = "Have you completed the current task? Answer YES or NO! If you're waiting, answer WAITING!";
    const MSG_CONTINUE =  "Сontinue with the task";
    const MSG_RESULT = "Repeat the final message";
    const MSG_CHECK_WORK2 = "Is the task complete? Answer only YES or NO! If NO, continue!";

    /**
     * Проверяем по ответу от LLM что она заверщила работу
     *
     * @param mixed $msgAnswer
     * @return boolean
     */
    public static function checkEndCycle(mixed $msgAnswer): bool {
        if ($msgAnswer) {
            if ($msgAnswer instanceof NeuronMessage) {
                $cnt = $msgAnswer->getContent();
                if ($cnt && (mb_strpos($cnt, 'YES') !== false || mb_strpos($cnt, 'WAITING') !== false)) {
                    // LLM четко ответила, что закончила работу
                    return true;
                }
                // YES не нашли
                return false;
            }
            // LLM ответила структурированными данными и значит завершила работу
            return true;
        }
        return false;
    }

    /**
     * Цикл для проверки завершения исполнения задания полностью
     *
     * @param ConfigurationAgent $agentCfg
     * @return array
     */
    public static function waitCycle(ConfigurationAgent $agentCfg): array {
        // здесь проверим, что пункт LLM исполнила - спросим ее прямо
        $msgTest = new NeuronMessage(MessageRole::USER, LlmCycleHelper::MSG_CHECK_WORK2);
        $cycleCount = 0;
        do {
            $msgAnswer = $agentCfg->sendMessage($msgTest);
            $cycleIsEnd = static::checkEndCycle($msgAnswer);
            $cycleCount++;
        } while(!$cycleIsEnd);
        return [
            'ok'     => true,
            'cycles' => $cycleCount
        ];
    }

    /**
     * Просим LLM повторить итоговое сообщение ибо в конце истории ждем именно его
     *
     * @param ConfigurationAgent $agentCfg
     * @return NeuronMessage|null|object
     */
    public static function repeateResultMsg(ConfigurationAgent $agentCfg): mixed {
        // LLM отработала задание и если сообщение последнее в цикле заданий, то надо, чтобы последнее сообщение истории было итоговым сообщением по заданиям
        $msgTest = new NeuronMessage(MessageRole::USER, LlmCycleHelper::MSG_RESULT);
        $msgAnswer = $agentCfg->sendMessage($msgTest);
        return $msgAnswer;
    }
}
