---
tools: store_load, store_save, todo_completed
pure_context: false
---

1. Загрузи значения `counter` и `target` из хранилища.
   Если хотя бы одно значение отсутствует — зафиксируй `completed = 0` через `todo_completed(status="not_done")`
   и верни сообщение об ошибке инициализации.

2. Увеличь `counter` на 1.
   Сохрани обновленное значение обратно по метке `counter`.

3. Проверь условие завершения:
   - если `counter >= target`, вызови `todo_completed(status="done", reason="Достигнут target")`;
   - иначе вызови `todo_completed(status="not_done", reason="Продолжаем цикл")`.

4. Верни короткий статус шага:
   - текущее значение `counter`;
   - значение `target`;
   - какой статус `completed` установлен.

