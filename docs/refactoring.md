## Подходы к рефакторингу

Этот документ фиксирует требования к рефакторингу и качеству кода для текущего проекта и соотносит их с реальной структурой и практиками в `src/`.

### Базовые принципы

При работе с кодом проекта следует придерживаться следующих правил:

- **Строгая типизация**:
  - использовать `declare(strict_types=1);` во всех PHP‑файлах (как это сделано в `src/classes`, `src/helpers`, `src/tools`);
  - по возможности указывать типы свойств, аргументов и возвращаемых значений.
- **SOLID / DRY / KISS**:
  - выносить повторяющуюся логику в абстрактные классы и хелперы (например, `AProducer`, `AttachmentHelper`, `FileContextHelper`, `ShellToolFactory`, `ToolRegistry`);
  - избегать избыточной сложности в методах, делить большие операции на небольшие, понятные шаги;
  - следить за единичной ответственностью классов (отдельно конфигурация, отдельно producers, отдельно хелперы).
- **Стиль PSR‑12 и статический анализ**:
  - код должен проходить `phpcs` с конфигурацией проекта (PSR‑12, каталоги `src` и `tests`);
  - код должен быть совместим с `phpstan` на максимальном уровне проекта.

Эти принципы отражены в текущих классах (например, разделение `ConfigurationApp` / `ConfigurationAgent`, `TodoListProducer` / `SkillProducer`, `RunLogger` / `FileLogger`) и должны соблюдаться при добавлении новых компонентов.

### Документирование кода

В соответствии с `AGENTS.md`:

- каждый класс должен иметь заголовочный комментарий с описанием предназначения класса;
- каждый метод — phpdoc‑описание функционала и параметров;
- комментарии используются для объяснения замысла и нетривиальных решений, а не для дублирования очевидного кода.

Проект уже следует этому подходу в большинстве новых классов (`AbstractPromptWithParams`, `TodoList`, `Skill`, `ConfigurationApp`, `ConfigurationAgent`, `DirPriority`, `FileLogger`, `RunLogger` и др.).

### Структурирование и вынос логики

При рефакторинге повторяющиеся и технические детали выносятся:

- в DTO (`ParamDto`, `ParamListDto`, `SessionParamsDto`, `RunStateDto`, `AttachmentDto` и др.);
- в traits (`LoggerAwareTrait`, `LoggerAwareContextualTrait`, `DependConfigAppTrait`, `HasNeedSkillsTrait`, `AttachesSkillToolsTrait`);
- в хелперы (`AttachmentHelper`, `FileContextHelper`, `PlaceholderHelper`, `MarkdownHelper`, `OptionsHelper`, `ChatHistoryTruncateHelper`, `ChatHistoryRollbackHelper`, `ShellToolFactory`, `ToolRegistry`).

Это упрощает сопровождение и уменьшает связность между доменными классами.

### Нормализация списков строк

Если нужно получить `list<string>` из массива “mixed” (например, конфигов), используйте:

- `app\modules\neuron\helpers\ArrayHelper::getUniqStrList(array $items): list<string>` — trim, пропуск пустых, дедупликация с сохранением порядка.

### Рекомендации по изменению существующего кода

При внесении правок:

- сначала обновить или создать тесты (unit/integration) для нового поведения;
- затем изменить код, сохраняя:
  - строгие типы;
  - соответствие PSR‑12;
  - проработанные phpdoc‑комментарии;
- после изменений обязательно запускать:
  - `./vendor/bin/phpcs`;
  - `./vendor/bin/phpstan`;
  - `./vendor/bin/phpunit`.

Эти требования уже учитываются в workflow текущих доработок (например, при добавлении `RunLogger`, `ShellToolFactory`, `ToolRegistry`, `SessionParamsDto` и расширении параметров `params` по умолчанию).

