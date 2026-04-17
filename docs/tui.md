## TUI (InteractiveCommand)

Этот документ описывает внутреннюю архитектуру интерактивного TUI-интерфейса команды `interactive`.

Важно: базовая реализация TUI вынесена в пакет `vendor/quanzo/tui`. Локальные классы TUI в `src/classes/tui` удалены как дубли.

### TUI находится в `vendor/quanzo/tui`

Source of truth для TUI — пакет `quanzo/tui`, подключённый в проект как `vendor/quanzo/tui`.

- **Класс команды**: `vendor/quanzo/tui/src/classes/command/InteractiveCommand.php` (`quanzo\tui\classes\command\InteractiveCommand`)
- **Handlers**: `vendor/quanzo/tui/src/classes/tui/command/handlers/*` (например, `/help`, `/ws`, `/clear`, `/exit`)
- **Рендер/ввод/состояние/terminal modes**: `vendor/quanzo/tui/src/classes/tui/**`
- **DTO/интерфейсы/enums/helpers**: `vendor/quanzo/tui/src/classes/dto/**`, `vendor/quanzo/tui/src/interfaces/**`, `vendor/quanzo/tui/src/enums/**`, `vendor/quanzo/tui/src/helpers/**`

### Назначение

Команда `interactive` использует класс из vendor-пакета: `vendor/quanzo/tui/src/classes/command/InteractiveCommand.php` и предоставляет простой текстовый UI:

- область вывода (история сообщений) с прокруткой;
- многострочное поле ввода (viewport 3 строки) с редактированием;
- строка состояния (mode/cursor/history count);
- переключение фокуса `Tab` (ввод/просмотр).

### Архитектура (компоненты)

- **Terminal режимы**: `vendor/quanzo/tui/src/classes/tui/terminal/TerminalModeManager.php`
  - включает `stty -icanon -echo`, alt-buffer, скрывает курсор;
  - включает **Bracketed Paste Mode** (`ESC[?2004h`) для корректной многострочной вставки;
  - обязательно использовать через `try/finally`, чтобы терминал гарантированно восстановился.

- **Ввод**: `vendor/quanzo/tui/src/classes/tui/input/`
  - `Utf8CharReader` — читает один UTF‑8 символ из потока;
  - `KeySequenceParser` — превращает поток в события `KeyEventDto`:
    - стрелки, PageUp/PageDown, Tab, Enter, Backspace, Delete, Home/End, Ctrl+C, текст;
    - **bracketed paste**: `ESC[200~...ESC[201~` → `TYPE_PASTE`.

- **Состояние**: `vendor/quanzo/tui/src/classes/dto/tui/`
  - `TuiStateDto` — модель состояния (history/input/cursor/focus/scroll + поля для partial redraw);
  - `LayoutDto` — вычисленная геометрия (координаты областей и `getOutputVisibleLines()`);
  - `TerminalSizeDto` — размеры терминала (width/height);
  - `KeyEventDto` — нормализованное событие клавиатуры.

- **Обработка событий (reducer)**: `vendor/quanzo/tui/src/classes/tui/state/TuiReducer.php`
  - единственное место, где описаны правила изменения `TuiStateDto` по `KeyEventDto`;
  - важно: логика остановки по `Ctrl+C` обрабатывается до вставки текста.

- **История (Variant C)**: `vendor/quanzo/tui/src/classes/dto/tui/history/`
  - `TuiHistoryDto` — список записей `TuiHistoryEntryDto`;
  - `TuiHistoryEntryDto` — атом истории (user_input/output/event) с `blocks` (виджетами) и `meta`.

- **Rich-вывод (виджеты)**:
  - `vendor/quanzo/tui/src/interfaces/tui/view/TuiBlockInterface.php` — контракт блока;
  - `vendor/quanzo/tui/src/classes/dto/tui/view/blocks/*` — блоки: `Text/Heading/Panel/Table/List/Code/Notice/Divider/KeyHints`;
  - `vendor/quanzo/tui/src/classes/tui/render/TuiHistoryFormatter.php` — форматтер entries/blocks → плоские строки;
  - `vendor/quanzo/tui/src/classes/dto/tui/view/TuiThemeDto.php` — ANSI-тема.

- **Hooks вывода (pre/post)**:
  - `vendor/quanzo/tui/src/interfaces/tui/TuiPreOutputHookInterface.php` — решает, какие entries добавить в историю и как управлять циклом (`clear/exit`);
  - `vendor/quanzo/tui/src/classes/dto/tui/TuiPreHookDecisionDto.php` — DTO решения pre-hook;
  - `vendor/quanzo/tui/src/interfaces/tui/TuiPostOutputHookInterface.php` — вызывается после рендера кадра, может дописать дополнительный многострочный вывод в history;
  - дефолтные реализации:
    - `vendor/quanzo/tui/src/classes/tui/hooks/WorkspaceTuiPreOutputHook.php` — собственная система команд (parser → dispatcher → handlers);
    - `vendor/quanzo/tui/src/classes/tui/hooks/DefaultTuiPostOutputHook.php` — возвращает `вывод + дата/время`.

### Wiring handlers (рекомендованный способ)

Регистрируйте TUI handlers через `InteractiveCommand`, чтобы `bin/console.php` оставался минимальным, а команда сама владела набором доступных TUI-команд:

```php
$interactive = (new \quanzo\tui\classes\command\InteractiveCommand())
    ->setCommandName('interactive')
    ->setDescriptionText('Интерактивный TUI (workspace)')
    ->addHandler(new \quanzo\tui\classes\tui\command\handlers\HelpCommandHandler())
    ->addHandler(new \quanzo\tui\classes\tui\command\handlers\WorkspaceCommandHandler())
    ->addHandler(new \quanzo\tui\classes\tui\command\handlers\ClearCommandHandler())
    ->addHandler(new \quanzo\tui\classes\tui\command\handlers\ExitCommandHandler())
    ->setPostHook(new \quanzo\tui\classes\tui\hooks\DefaultTuiPostOutputHook());

$app->add($interactive);
```

- **Отрисовка**: `vendor/quanzo/tui/src/classes/tui/render/TuiRenderer.php`
  - full render (очистка и полная отрисовка рамок/контента/курсора);
  - partial render (обновление только изменившихся строк ввода и статус-бара);
  - для переносов/выравнивания использует `vendor/quanzo/tui/src/helpers/TuiTextHelper.php`.
- для операций над буфером ввода использует `vendor/quanzo/tui/src/helpers/TuiInputBufferHelper.php`.

### Поток данных

```mermaid
flowchart TD
InteractiveCommand -->|stdin| KeySequenceParser
KeySequenceParser -->|KeyEventDto| TuiReducer
TuiReducer -->|ReducerResultDto| InteractiveCommand
InteractiveCommand -->|PreHook| HistoryEntries
HistoryEntries -->|Formatter| TuiHistoryFormatter
InteractiveCommand -->|TuiStateDto,LayoutDto,TerminalSizeDto| TuiRenderer
TuiRenderer -->|ANSI output| Terminal
InteractiveCommand -->|PostHook (after render)| HistoryEntries
```

### Порядок вызовов pre/post hooks

- при Enter `TuiReducer` возвращает `submittedInput` (но сам ничего не пишет в историю);
- `InteractiveCommand` вызывает **pre-hook**, который возвращает `TuiPreHookDecisionDto`:
  - entries для добавления;
  - флаги `clearHistory/exit`;
- `InteractiveCommand` применяет решение (очистка/append/exit);
- выполняется фактическая отрисовка кадра (`renderFull`/`renderPartial`);
- **после рендера** `InteractiveCommand` вызывает **post-hook** и, если тот вернул текст, добавляет его в history;
- добавленное post-hook будет видно на **следующем кадре** (т.к. хук вызывается именно после рендера).

### Тесты

Базовые тесты TUI находятся в репозитории/пакете `quanzo/tui` (в `vendor/quanzo/tui/tests`).

