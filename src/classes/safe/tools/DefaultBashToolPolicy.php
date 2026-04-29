<?php

declare(strict_types=1);

namespace app\modules\neuron\classes\safe\tools;

use app\modules\neuron\classes\safe\dto\RuleMetadataDto;
use app\modules\neuron\classes\safe\dto\ToolRulePatternDto;
use app\modules\neuron\classes\safe\enums\RuleSeverityEnum;

/**
 * Дефолтная high-confidence политика блокировок для `BashTool`.
 *
 * Правила адаптированы из `opencode-policy`, но намеренно сведены к узкому
 * набору: секреты, exfiltration, reverse shell, resource abuse, obfuscation и
 * выход из workspace. Полный список unsafe-tool правил не импортируется, чтобы
 * не ломать легитимные команды проекта.
 *
 * Пример:
 * ```php
 * $patterns = DefaultBashToolPolicy::patterns();
 * ```
 */
class DefaultBashToolPolicy
{
    /**
     * Возвращает отфильтрованный список regex-паттернов для `BashTool`.
     *
     * @return list<ToolRulePatternDto> Список tool-правил с метаданными.
     */
    public static function rules(): array
    {
        return [
            self::makeRule(
                'tool.secrets.proc_environ',
                'tool.secrets',
                '/\/proc\/self\/environ/i',
                'Blocks reading current process environment.',
                'Low: legitimate code rarely needs to expose /proc/self/environ to LLM.'
            ),
            self::makeRule(
                'tool.secrets.run_secrets',
                'tool.secrets',
                '/\/run\/secrets/i',
                'Blocks reading container secret mounts.',
                'Low: secret mount paths should not be read by LLM commands.'
            ),
            self::makeRule(
                'tool.secrets.dotenv',
                'tool.secrets',
                '/\b(cat|less|more|head|tail|grep)\b\s+.*\.env\b/i',
                'Blocks direct reads/searches of .env files.',
                'Medium: debugging config can touch .env, but LLM should not print it.'
            ),
            self::makeRule(
                'tool.exfiltration.curl_post',
                'tool.exfiltration',
                '/\bcurl\b\s+.*(-d|--data|--data-raw|--data-binary|-F|-T|--upload-file)\s/i',
                'Blocks curl data upload/exfiltration patterns.',
                'Medium: API testing can use POST, so disable this rule for trusted workflows.'
            ),
            self::makeRule(
                'tool.exfiltration.wget_post',
                'tool.exfiltration',
                '/\bwget\b\s+.*(--post-data|--post-file)\b/i',
                'Blocks wget POST upload/exfiltration patterns.',
                'Medium: rare legitimate use in automation.'
            ),
            self::makeRule(
                'tool.reverse_shell.dev_tcp',
                'tool.reverse_shell',
                '/\/dev\/tcp\/|\/dev\/udp\//i',
                'Blocks shell network pseudo-devices used in reverse shells.',
                'Low: these paths are strongly associated with shell networking bypass.'
            ),
            self::makeRule(
                'tool.reverse_shell.bash_i',
                'tool.reverse_shell',
                '/\bbash\s+-i\b/i',
                'Blocks interactive bash often used in reverse shells.',
                'Low: BashTool is non-interactive; bash -i is not needed.'
            ),
            self::makeRule(
                'tool.reverse_shell.netcat_exec',
                'tool.reverse_shell',
                '/\bnc\b\s+.*-(e|c)\s/i',
                'Blocks netcat command execution flags.',
                'Low: nc -e/-c is a common reverse shell primitive.'
            ),
            self::makeRule(
                'tool.resource_abuse.fork_bomb',
                'tool.resource_abuse',
                '/\(\s*\)\s*\{\s*\||for.*do.*&.*done|while.*do.*&.*done/i',
                'Blocks fork-bomb and background process fan-out patterns.',
                'Low: these constructs are dangerous in agent-controlled shell.'
            ),
            self::makeRule(
                'tool.resource_abuse.fallocate_huge',
                'tool.resource_abuse',
                '/\bfallocate\s+-l\s*[0-9]+[GT]\b/i',
                'Blocks huge file allocation.',
                'Low: creating multi-GB files is not safe in agent workflows.'
            ),
            self::makeRule(
                'tool.obfuscation.ifs',
                'tool.obfuscation',
                '/\$\{?IFS\}?/',
                'Blocks IFS variable command obfuscation.',
                'Medium: IFS can be legitimate shell knowledge but is high-risk in LLM tools.'
            ),
            self::makeRule(
                'tool.obfuscation.command_substitution',
                'tool.obfuscation',
                '/`[^`]+`|\$\([^)]+\)/',
                'Blocks command substitution used to hide exfiltration or nested execution.',
                'Medium: command substitution is common shell syntax; disable when needed.'
            ),
            self::makeRule(
                'tool.workspace_escape.cd_parent',
                'tool.workspace_escape',
                '/\bcd\s+\.\./',
                'Blocks navigation outside workspace via parent directory.',
                'Medium: some maintenance tasks may cd .., but agent tools should stay scoped.'
            ),
        ];
    }

    /**
     * Возвращает только PCRE-паттерны для передачи в `BashTool`.
     *
     * @param list<ToolRulePatternDto>|null $rules Предварительно отфильтрованные DTO правил.
     *
     * @return list<string> Список regex-паттернов.
     */
    public static function patterns(?array $rules = null): array
    {
        $rules ??= self::rules();
        return array_map(
            static fn (ToolRulePatternDto $rule): string => $rule->getPattern(),
            $rules
        );
    }

    /**
     * Создаёт DTO одного tool-правила с полной документацией метаданных.
     *
     * @param string $ruleId            Стабильный id правила.
     * @param string $group             Группа правила.
     * @param string $pattern           PCRE-паттерн для blockedPatterns.
     * @param string $description       Описание назначения правила.
     * @param string $falsePositiveRisk Описание риска ложного срабатывания.
     *
     * @return ToolRulePatternDto DTO tool-правила.
     */
    private static function makeRule(
        string $ruleId,
        string $group,
        string $pattern,
        string $description,
        string $falsePositiveRisk
    ): ToolRulePatternDto {
        $metadata = (new RuleMetadataDto())
            ->setRuleId($ruleId)
            ->setGroup($group)
            ->setSeverity(RuleSeverityEnum::HIGH)
            ->setDescription($description)
            ->setFalsePositiveRisk($falsePositiveRisk);

        return (new ToolRulePatternDto())
            ->setMetadata($metadata)
            ->setPattern($pattern);
    }
}
