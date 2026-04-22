## Safe-инфраструктура LLM

Документ описывает подсистему защиты сообщений LLM, размещённую в `src/classes/safe/`.

### Цель

Подсистема вводит два уровня защиты:

- вход (`InputSafe`) — очистка текста и блокировка prompt-injection/jailbreak;
- выход (`OutputSafe`) — редактирование чувствительных фрагментов и сигнализация о потенциальной утечке.

### Подключение в конфигурации агента

Поля `ConfigurationAgent`:

- `safeInput` (bool, default `true`) — включение входной защиты;
- `safeOutput` (bool, default `true`) — включение выходной защиты.

Экземпляры `InputSafe` и `OutputSafe` создаются в `ConfigurationApp`:

- `ConfigurationApp::getInputSafe()`;
- `ConfigurationApp::getOutputSafe()`.

Далее они передаются в `SafeAIProviderDecorator`, который оборачивает LLM-провайдер.

### Архитектура каталогов

- `src/classes/safe/InputSafe.php` — фасад двухэтапной входной защиты;
- `src/classes/safe/OutputSafe.php` — фасад выходной проверки/редактирования;
- `src/classes/safe/SafeAIProviderDecorator.php` — декоратор провайдера, применяющий Input/Output Safe;
- `src/classes/safe/contracts` — интерфейсы расширяемых правил;
- `src/classes/safe/rules/input` — sanitize/detect правила для input;
- `src/classes/safe/rules/output` — detect/redact правила для output;
- `src/classes/safe/dto` — DTO нарушений и результатов;
- `src/classes/safe/exceptions` — исключения блокировок.

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

При срабатывании детекции выбрасывается `InputSafetyViolationException`, и запрос не отправляется в LLM.

### Стартовые правила OutputSafe

- редактирование фрагментов, похожих на утечку системных инструкций (`system_prompt_leak`);
- редактирование токеноподобных секретов (`api_key_leak`).

`OutputSafe` не блокирует ответ: он возвращает безопасный текст + список нарушений (`OutputSafeResultDto`), а декоратор пишет предупреждение `llm.output.redacted`.

### Расширение без изменения ядра

Чтобы добавить новое правило:

1. Реализовать нужный интерфейс в `src/classes/safe/contracts`:
   - `InputSanitizerRuleInterface`,
   - `InputDetectorRuleInterface`,
   - `OutputDetectorRuleInterface`.
2. Добавить правило в дефолтный пайплайн в `ConfigurationApp::buildDefaultInputSafe()` или `ConfigurationApp::buildDefaultOutputSafe()`.
3. Добавить unit-тесты для нового правила.

Таким образом новые фильтры подключаются как плагины правил без изменения классов `InputSafe` и `OutputSafe`.

