<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\skill;

use app\modules\neuron\classes\APromptComponent;
use app\modules\neuron\interfaces\ISkill;
use app\modules\neuron\helpers\CommentsHelper;

/**
 * Класс текстового навыка (Skill).
 *
 * Хранит текстовый шаблон с опциями и поддерживает подстановку
 * параметров вида $1, $2 и т.д. при получении финального текста.
 */
class Skill extends APromptComponent implements ISkill
{
    /**
     * Создает навык на основе входного текстового описания.
     *
     * Текст может содержать:
     *  - только тело навыка;
     *  - блок опций и тело, разделенные линиями из '-';
     *  - только блок опций (без тела);
     *  - быть пустым (без опций и тела).
     *
     * @param string $input Полный текст описания навыка.
     */
    public function __construct(string $input)
    {
        parent::__construct($input);
        $this->body = CommentsHelper::stripComments($this->body);
    }

    /**
     * Возвращает текст навыка с подставленными параметрами.
     *
     * Каждый параметр подставляется в соответствующий плейсхолдер:
     *  - первый параметр заменяет все вхождения "$1";
     *  - второй параметр заменяет все вхождения "$2" и т.д.
     * Плейсхолдеры без переданных значений заменяются на пустую строку.
     *
     * @param mixed ...$params Значения параметров для подстановки.
     */
    public function getSkill(...$params): string
    {
        $template = $this->getBody();

        if ($template === '') {
            return '';
        }

        // Находим все плейсхолдеры вида $<num> в тексте.
        $matches = [];
        preg_match_all('/\$(\d+)/', $template, $matches);

        $replacements = [];

        if (!empty($matches[1])) {
            // Для каждого уникального номера определяем значение или пустую строку.
            foreach (array_unique($matches[1]) as $num) {
                $index = (int) $num - 1;
                $value = array_key_exists($index, $params) ? (string) $params[$index] : '';
                $replacements['$' . $num] = $value;
            }
        }

        if ($replacements === []) {
            return $template;
        }

        // Выполняем замены за один проход, чтобы избежать пересечений ($1 и $10 и т.п.).
        return strtr($template, $replacements);
    }
}
