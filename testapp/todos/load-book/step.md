---
agent: agent-main
pure_context: false
tools: todo_goto, todo_completed
skills: skill-file-block-summarize
---

1. Загрузи фрагмент файла `"temp/ispytanie.txt"` начиная со строки `startLine` и составь краткое изложение содержимого

2. По результатам загрузки определи последнюю загруженную строку и сохрани ее в переменную `startLine`.

3. Если файл полностью прочитан, то установи `completed = 1` иначе установи `completed = 0`

4. Если `completed` не равно 1, то **ПЕРЕЙДИ К ПУНКТУ №1** используя инструмент `todo_goto`
