## Safe-инфраструктура LLM

Документ описывает подсистему защиты сообщений LLM, размещённую в `src/classes/safe/`.

### Цель

Подсистема вводит два уровня защиты:

- вход (`InputSafe`) — очистка текста и блокировка prompt-injection/jailbreak;
- выход (`OutputSafe`) — редактирование чувствительных фрагментов и сигнализация о потенциальной утечке.
- инструменты (`safe.tools.*`) — отдельные политики для опасных tool-вызовов, сейчас для `BashTool`.

### Подключение в конфигурации агента

Поля `ConfigurationAgent`:

- `safeInput` (bool, default `true`) — включение входной защиты;
- `safeOutput` (bool, default `true`) — включение выходной защиты.

Экземпляры `InputSafe` и `OutputSafe` создаются в `ConfigurationApp`:

- `ConfigurationApp::getInputSafe()`;
- `ConfigurationApp::getOutputSafe()`.

Далее они передаются в `SafeAIProviderDecorator`, который оборачивает LLM-провайдер.

### Конфигурация `safe.*`

Правила можно отключать без изменения PHP-кода:

```jsonc
{
  "safe": {
    "input": {
      "enabled": true,
      "disabled_rules": ["input.prompt.reset_ru"],
      "disabled_groups": ["input.low_confidence"]
    },
    "output": {
      "enabled": true,
      "disabled_rules": ["output.secret.env_assignment"],
      "disabled_groups": []
    },
    "tools": {
      "bash": {
        "enabled": true,
        "disabled_rules": ["tool.secrets.dotenv"],
        "disabled_groups": ["tool.obfuscation"]
      }
    }
  }
}
```

Инварианты:

- `ConfigurationAgent::$safeInput` и `ConfigurationAgent::$safeOutput` остаются быстрыми agent-level флагами.
- `safe.input.enabled=false` создаёт пустой `InputSafe` pipeline.
- `safe.output.enabled=false` создаёт пустой `OutputSafe` pipeline.
- `safe.tools.bash.enabled=false` не добавляет дефолтные blockedPatterns в `BashTool`.
- Группа `input.low_confidence` отключена по умолчанию, чтобы не блокировать обычные roleplay-запросы.

### Архитектура каталогов

- `src/classes/safe/InputSafe.php` — фасад двухэтапной входной защиты;
- `src/classes/safe/OutputSafe.php` — фасад выходной проверки/редактирования;
- `src/classes/safe/SafeAIProviderDecorator.php` — декоратор провайдера, применяющий Input/Output Safe;
- `src/classes/safe/contracts` — интерфейсы расширяемых правил;
- `src/classes/safe/rules/input` — sanitize/detect правила для input;
- `src/classes/safe/rules/output` — detect/redact правила для output;
- `src/classes/safe/dto` — DTO нарушений и результатов;
- `src/classes/safe/exceptions` — исключения блокировок.
- `src/classes/safe/tools` — дефолтные политики для tool-слоя (`DefaultBashToolPolicy`).
- `src/classes/safe/enums` — enum-значения инфраструктуры, например `RuleSeverityEnum`.

### Метаданные правил

Каждое правило реализует `SafeRuleInterface` и возвращает `RuleMetadataDto`:

- `ruleId` — стабильный идентификатор для `disabled_rules`;
- `group` — группа для `disabled_groups`;
- `severity` — `low`, `medium`, `high`;
- `description` — что правило ищет или редактирует;
- `falsePositiveRisk` — когда правило может сработать на легитимный текст.

Пример ruleId:

```text
input.prompt.reset_ru
output.secret.env_assignment
tool.reverse_shell.dev_tcp
```

### Стартовые правила InputSafe

Санитизация:

- удаление control/unprintable и zero-width символов (`RemoveInvisibleCharsInputRule`);
- нормализация whitespace (`NormalizeWhitespaceInputRule`);
- схлопывание аномальных повторов символов (`CollapseRepeatCharsInputRule`);
- ограничение длины входа (`MaxLengthInputRule`).

Детекция (блокирующие правила):

- override системных инструкций (`RegexInjectionInputRule`, код `instruction_override`);
- попытка извлечения системного промпта (`RegexInjectionInputRule`, код `system_prompt_exfiltration`);
- role-hijack/jailbreak маркеры (`RegexInjectionInputRule`, код `jailbreak_role_hijack`);
- обфускация ключевых слов (typoglycemia, `TypoglycemiaInputRule`).
- русскоязычный сброс инструкций (`input.prompt.reset_ru`);
- fake role tags `[system]`, `[admin]`, `[developer]` (`input.prompt.fake_role_tag`);
- injection block `new instructions:` (`input.prompt.new_instructions`);
- `override/disregard/reset` поведение (`input.prompt.override_reset`);
- расширенная prompt-exfiltration (`input.prompt.exfiltration_extended`);
- base64/decode+exec и известные base64-литералы секретов (`input.obfuscation.*`).

Низкосигнальные roleplay-паттерны (`act as`, `pretend you are`, `you are now`) находятся в группе `input.low_confidence` и выключены по умолчанию.

При срабатывании детекции выбрасывается `InputSafetyViolationException`, и запрос не отправляется в LLM.

### Стартовые правила OutputSafe

- редактирование фрагментов, похожих на утечку системных инструкций (`system_prompt_leak`);
- редактирование токеноподобных секретов (`api_key_leak`).
- env-like секреты (`output.secret.env_assignment`);
- чувствительные пути `/proc/self/environ`, `/run/secrets`, `.env` (`output.secret.sensitive_paths`);
- утечки внутренних policy/developer instructions (`output.prompt.policy_leak`).

`OutputSafe` не блокирует ответ: он возвращает безопасный текст + список нарушений (`OutputSafeResultDto`), а декоратор пишет предупреждение `llm.output.redacted`.

### BashTool Safe-политики

Политики `BashTool` не добавляются в `InputSafe`, потому что они проверяют не текст prompt, а shell-команду перед выполнением.

`DefaultBashToolPolicy` добавляет high-confidence `blockedPatterns` при вызове `BashTool::setAgentCfg()`:

- `tool.secrets` — `.env`, `/proc/self/environ`, `/run/secrets`;
- `tool.exfiltration` — `curl`/`wget` upload/POST;
- `tool.reverse_shell` — `/dev/tcp`, `bash -i`, `nc -e/-c`;
- `tool.resource_abuse` — fork-bomb, huge `fallocate`;
- `tool.obfuscation` — `$IFS`, backticks, `$()`;
- `tool.workspace_escape` — `cd ..`.

Существующие `allowedPatterns/blockedPatterns` инструмента сохраняются: safe-паттерны добавляются к пользовательскому списку.

### Расширение без изменения ядра

Чтобы добавить новое правило:

1. Реализовать нужный интерфейс в `src/classes/safe/contracts`:
   - `InputSanitizerRuleInterface`,
   - `InputDetectorRuleInterface`,
   - `OutputDetectorRuleInterface`.
2. Вернуть корректный `RuleMetadataDto` из `getMetadata()`.
3. Добавить правило в дефолтный пайплайн в `ConfigurationApp::buildDefaultInputSafe()` или `ConfigurationApp::buildDefaultOutputSafe()`.
4. Добавить unit-тесты для нового правила и тест отключения по `ruleId/group`.

Таким образом новые фильтры подключаются как плагины правил без изменения классов `InputSafe` и `OutputSafe`.

