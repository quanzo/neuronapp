# Шина событий `EventBus`

Документ описывает поведение синхронной шины событий `EventBus` (`src/classes/events/EventBus.php`).

## Назначение

Класс хранит обработчики в памяти процесса и позволяет:

- подписывать обработчик на событие через `EventBus::on()`;
- отписывать обработчик через `EventBus::off()`;
- вызывать событие через `EventBus::trigger()`;
- очищать подписки через `EventBus::clear()`.

## Контекстный ключ события

Для подписки и вызова используется контекстный ключ:

- `*` — глобальный ключ события;
- `class-string` — ключ конкретного класса (`class:<FQCN>`);
- `object` — ключ конкретного объекта (`object:<id>` через `spl_object_id`).

Правила:

- в `on()` / `off()` передача объекта привязывает обработчик строго к конкретному экземпляру;
- в `on()` / `off()` передача строки привязывает обработчик к точному имени класса;
- в `trigger()` для объекта выполняются обработчики в порядке:
  - `object:<id>` -> `class:<объект>` -> `class:<родители>` -> `*`;
- в `trigger()` для имени класса выполняются:
  - `class:<FQCN>` -> `*`.

## Остановка цепочки обработчиков

При вызове события каждый обработчик получает payload.
Если обработчик возвращает `false`, дальнейшие обработчики для найденного контекстного ключа не выполняются.

Обработчик вызывается с двумя аргументами:

- `payload` (данные события);
- `initiator` (второй аргумент `$_class` из `EventBus::trigger()`).

Если callback объявлен только с одним аргументом (payload), это не является ошибкой.

## Очистка подписок

- `EventBus::clear()` без аргументов удаляет все зарегистрированные обработчики.
- `EventBus::clear('event.name')` удаляет подписки только указанного события.

Эта операция полезна в тестах и long-running процессах для изоляции состояния.

## Имена событий и DTO

Для имен событий используется enum `EventNameEnum` (`src/enums/EventNameEnum.php`).

Payload события передаётся как DTO:

- `RunEventDto` (`src/classes/dto/events/RunEventDto.php`)
- `TodoEventDto` (`src/classes/dto/events/TodoEventDto.php`)
- `SkillEventDto` (`src/classes/dto/events/SkillEventDto.php`)
- `OrchestratorEventDto` (`src/classes/dto/events/OrchestratorEventDto.php`)
- `ToolEventDto` (`src/classes/dto/events/ToolEventDto.php`)

Это позволяет уйти от неструктурированных массивов и стабилизировать контракт данных.

## Каталог событий

- `run.started`
- `run.finished`
- `run.failed`
- `todo.started`
- `todo.completed`
- `todo.failed`
- `todo.goto_requested`
- `todo.goto_rejected`
- `todo.agent_switched`
- `skill.started`
- `skill.completed`
- `skill.failed`
- `tool.started`
- `tool.completed`
- `tool.failed`
- `orchestrator.cycle_started`
- `orchestrator.step_completed`
- `orchestrator.completed`
- `orchestrator.failed`
- `orchestrator.restarted`

## Подписчики

Подписчики находятся в `src/classes/events/subscribers`.

Текущий подписчик:

- `RunLoggingSubscriber` — логирует `run.*` события в PSR-3 логгер.
- `ToolLoggingSubscriber` — логирует `tool.*` события в PSR-3 логгер.
- `SkillLoggingSubscriber` — логирует `skill.*` события в PSR-3 логгер.
- `TodoListLoggingSubscriber` — логирует `todo.*` события в PSR-3 логгер.

## Поле agentName в DTO

Базовый `BaseEventDto` содержит поле `agentName`.

- В самом DTO хранится ссылка на объект `ConfigurationAgent` (если доступен).
- В `toArray()` выводится только имя агента (`agentName`).
- Полный объект конфигурации агента (`agent cfg`) в payload не сериализуется.

Регистрация подписчиков выполняется напрямую в `AbstractAgentCommand::resolveFileLogger()`.
