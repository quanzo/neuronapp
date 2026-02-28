<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\skill;

use Amp\Future;
use app\modules\neuron\classes\APromptComponent;
use app\modules\neuron\ConfigurationAgent;
use app\modules\neuron\helpers\CommentsHelper;
use app\modules\neuron\interfaces\ISkill;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Класс текстового навыка (Skill).
 *
 * Хранит текстовый шаблон с опциями и поддерживает подстановку
 * именованных параметров вида $paramName при получении финального текста.
 * Имя параметра — только латинские буквы [a-zA-Z]+, регистр учитывается.
 */
class Skill extends APromptComponent implements ISkill
{
    private string $name;

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
     * @param string $name  Имя навыка (имя файла с поддиректорией, если есть).
     */
    public function __construct(string $input, string $name = '')
    {
        parent::__construct($input);
        $this->body = CommentsHelper::stripComments($this->body);
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Возвращает текст навыка с подставленными именованными параметрами.
     *
     * Каждый ключ массива соответствует плейсхолдеру в шаблоне:
     * ключ "query" заменяет все вхождения "$query" и т.д.
     * Имя параметра — только латинские буквы [a-zA-Z]+, регистр учитывается.
     * Плейсхолдеры без переданных значений заменяются на пустую строку.
     *
     * @param array<string, mixed> $params Именованные параметры для подстановки.
     */
    public function getSkill(array $params = []): string
    {
        $template = $this->getBody();

        if ($template === '') {
            return '';
        }

        $matches = [];
        preg_match_all('/\$([a-zA-Z]+)/', $template, $matches);

        $replacements = [];

        if (!empty($matches[1])) {
            foreach (array_unique($matches[1]) as $placeholder) {
                $value = array_key_exists($placeholder, $params) ? (string) $params[$placeholder] : '';
                $replacements['$' . $placeholder] = $value;
            }
        }

        if ($replacements === []) {
            return $template;
        }

        return strtr($template, $replacements);
    }

    /**
     * Генерирует LLM-инструмент ({@see Tool}) на основе навыка.
     *
     * При вызове инструмента подставляются параметры в шаблон навыка,
     * результат отправляется в LLM через переданную конфигурацию агента,
     * и возвращается ответ модели.
     *
     * Имя инструмента строится из имени файла навыка (с поддиректорией),
     * описание берётся из опции "description", параметры — из опции "params".
     *
     * Формат "params" — JSON-объект, где ключ = имя параметра, значение:
     *  - строка (тип, напр. "string") или
     *  - объект {"type": "...", "description": "...", "required": true/false}.
     *
     * @param ConfigurationAgent $agentCfg Конфигурация агента-исполнителя.
     * @param MessageRole        $role     Роль сообщения, отправляемого агенту.
     */
    public function getTool(ConfigurationAgent $agentCfg, MessageRole $role = MessageRole::USER): Tool
    {
        $options = $this->getOptions();

        $toolName = str_replace('/', '__', $this->name);
        $description = $options['description'] ?? null;

        $tool = new Tool($toolName, is_string($description) ? $description : null);

        $paramsDef = $options['params'] ?? [];

        if (is_array($paramsDef)) {
            foreach ($paramsDef as $paramName => $def) {
                if (!is_string($paramName) || preg_match('/^[a-zA-Z]+$/', $paramName) !== 1) {
                    continue;
                }

                if (is_string($def)) {
                    $tool->addProperty(new ToolProperty($paramName, PropertyType::from($def)));
                } elseif (is_array($def)) {
                    $tool->addProperty(new ToolProperty(
                        $paramName,
                        PropertyType::from($def['type'] ?? 'string'),
                        $def['description'] ?? null,
                        (bool) ($def['required'] ?? false),
                    ));
                }
            }
        }

        $tool->setCallable(function (mixed ...$args) use ($agentCfg, $role): mixed {
            $text = $this->getSkill($args);
            $message = new NeuronMessage($role, $text);
            return $agentCfg->sendMessage($message);
        });

        return $tool;
    }

    /**
     * @inheritDoc
     */
    public function executeFromAgent(ConfigurationAgent $agentCfg, MessageRole $role = MessageRole::USER): Future
    {
        $body = $this->getBody();
        return \Amp\async(function () use ($agentCfg, $body, $role): mixed {
            $message = new NeuronMessage($role, $body);
            return $agentCfg->sendMessage($message);
        });
    }
}
