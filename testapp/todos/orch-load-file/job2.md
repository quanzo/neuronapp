---
tools: chunk_view
pure_context: false
agent: agent-orch
---

1. Прочитай чанк, используя `chunk_view` с параметрами:
   - `path`: путь к файлу
   - `start_line`: текущее значение `start_line`
   - `lines`: 100
   - `max_chars`: 10000  
   Из результата возьми `startLine`, `endLine`, `chunk`.

2. Сохрани чанк в переменной с именем `summary_part_N`, где N — номер чанка.

3. **Обнови прогресс**  
   - `start_line = endLine + 1`.
   - `part = part + 1`

4. **Проверь завершение**  
   Если `start_line >= total_lines`, установи ключ `completed = 1` иначе `completed = 0`

5. Выведи свой статус
