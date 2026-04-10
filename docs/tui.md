## TUI (InteractiveCommand)

Этот документ описывает внутреннюю архитектуру интерактивного TUI-интерфейса команды `interactive`.

### Назначение

Команда `interactive` (`src/classes/command/InteractiveCommand.php`) предоставляет простой текстовый UI:

- область вывода (история сообщений) с прокруткой;
- многострочное поле ввода (3 строки) с редактированием;
- строка состояния (mode/cursor/history count);
- переключение фокуса `Tab` (ввод/просмотр).

### Архитектура (компоненты)

- **Terminal режимы**: `src/classes/command/terminal/TerminalModeManager.php`
  - включает `stty -icanon -echo`, alt-buffer, скрывает курсор;
  - обязательно использовать через `try/finally`, чтобы терминал гарантированно восстановился.

- **Ввод**: `src/classes/command/input/`
  - `Utf8CharReader` — читает один UTF‑8 символ из потока;
  - `KeySequenceParser` — превращает поток в события `KeyEventDto`:
    - стрелки, PageUp/PageDown, Tab, Enter, Backspace, Ctrl+C, текст.

- **Состояние**: `src/classes/dto/tui/`
  - `TuiStateDto` — модель состояния (history/input/cursor/focus/scroll + поля для partial redraw);
  - `LayoutDto` — вычисленная геометрия (координаты областей и `getOutputVisibleLines()`);
  - `TerminalSizeDto` — размеры терминала (width/height);
  - `KeyEventDto` — нормализованное событие клавиатуры.

- **Обработка событий (reducer)**: `src/classes/command/state/TuiReducer.php`
  - единственное место, где описаны правила изменения `TuiStateDto` по `KeyEventDto`;
  - важно: логика остановки по `Ctrl+C` обрабатывается до вставки текста.

- **Hooks вывода (pre/post)**:
  - `src/interfaces/tui/TuiPreOutputHookInterface.php` — решает, какой текст добавлять в history после Enter (или отменяет вывод);
  - `src/interfaces/tui/TuiPostOutputHookInterface.php` — вызывается после рендера кадра, может дописать дополнительный многострочный вывод в history;
  - дефолтные реализации:
    - `src/classes/command/hooks/DefaultTuiPreOutputHook.php` — возвращает текст как есть;
    - `src/classes/command/hooks/DefaultTuiPostOutputHook.php` — возвращает `вывод + дата/время`.

- **Отрисовка**: `src/classes/command/render/TuiRenderer.php`
  - full render (очистка и полная отрисовка рамок/контента/курсора);
  - partial render (обновление только изменившихся строк ввода и статус-бара);
  - для переносов/выравнивания использует `src/helpers/TuiTextHelper.php`.

### Поток данных

```mermaid
flowchart TD
InteractiveCommand -->|stdin| KeySequenceParser
KeySequenceParser -->|KeyEventDto| TuiReducer
TuiReducer -->|ReducerResultDto| InteractiveCommand
InteractiveCommand -->|PreHook| History
InteractiveCommand -->|TuiStateDto,LayoutDto,TerminalSizeDto| TuiRenderer
TuiRenderer -->|ANSI output| Terminal
InteractiveCommand -->|PostHook (after render)| History
```

### Порядок вызовов pre/post hooks

- при Enter `TuiReducer` возвращает `submittedInput` (но сам ничего не пишет в history);
- `InteractiveCommand` вызывает **pre-hook** и, если тот разрешил вывод, добавляет текст в history;
- выполняется фактическая отрисовка кадра (`renderFull`/`renderPartial`);
- **после рендера** `InteractiveCommand` вызывает **post-hook** и, если тот вернул текст, добавляет его в history;
- добавленное post-hook будет видно на **следующем кадре** (т.к. хук вызывается именно после рендера).

### Тесты

Тесты на «чистые» части вынесены в `tests/Tui/`:

- `KeySequenceParserTest` — распознавание событий (включая ESC-последовательности и UTF‑8);
- `TuiReducerTest` — граничные условия редактирования/скролла/фокуса;
- `LayoutDtoTest` — вычисление производных значений.
- `DefaultTuiPreOutputHookTest` — поведение дефолтного pre-hook;
- `DefaultTuiPostOutputHookTest` — поведение дефолтного post-hook и формат даты/времени.

