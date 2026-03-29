# JSON в проекте

Единая политика сериализации и разбора JSON задаётся классом `app\modules\neuron\helpers\JsonHelper` (`src/helpers/JsonHelper.php`).

## Флаги

- **Unicode** — `JSON_UNESCAPED_UNICODE` для логов, ответов инструментов и файлов без `\uXXXX` для кириллицы.
- **Строгая запись** — `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` (чекпоинты, индекс VarStorage, JSON-вывод инструментов).
- **Fallback UTF-8** — при невозможности строго закодировать полезную нагрузку используется `JSON_INVALID_UTF8_SUBSTITUTE` (см. `JsonHelper::encodeUnicodeWithUtf8Fallback()`, как в `VarStorage::save()`).
- **История чата в файле** — pretty-print + throw: `JsonHelper::encodeUnicodePrettyThrow()`.

## Типовые вызовы

| Сценарий | Метод |
|----------|--------|
| Ответ инструмента / DTO в строку | `JsonHelper::encodeThrow($dto->toArray())` |
| Лог контекста (без throw) | `JsonHelper::encode($context)` |
| Чтение JSON из файла в массив или `null` | `JsonHelper::tryDecodeAssociativeArray($raw)` |
| `json_decode($x, true) ?? []` | `JsonHelper::decodeAssociativeOrEmpty($x)` |
| Конфиг `config.jsonc` после `CommentsHelper::stripComments` | `JsonHelper::decodeAssociativeForConfigFile($clean, $path)` |
| Разбор с исключением при ошибке | `JsonHelper::decodeAssociativeThrow($json)` |

Прямые вызовы `json_encode` / `json_decode` в прикладном коде не используются — только внутри `JsonHelper`.
