---
tools: chunk_view
pure_context: false
agent: agent-main
---

1. **Прочитай очередной чанк**  
   Вызови `chunk_view` с параметрами:
   - `path`: путь к файлу
   - `start_line`: текущее значение `start_line`
   - `lines`: 100
   - `max_chars`: 10000  
   Из результата возьми `startLine`, `endLine`, `chunk`.

2. **Составь краткое резюме**  
   На основе `chunk` составь 1–3 предложения, описывающие основное содержание.  
   Сохрани резюме в хранилище с ключом `summary_part_N`, где N — номер чанка.

3. **Обнови прогресс**  
   - Установи новый `start_line = endLine + 1`.
   - Установи новый `part = part + 1`

4. **Проверь завершение**  
   Если `start_line >= total_lines`, установи ключ `completed = 1` иначе `completed = 0`

5. Выведи свой статус
