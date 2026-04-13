---
description: Переводит текст на указанный язык
params: {"text":{"type":"string","description":"Текст для перевода","required":true}, "targetLang":{"type":"string","description":"Zpsr на который переводим текст","required":true}}
pure_context: true
agent: agent-main
---

Переведи следующий текст на язык: `$targetLang`.

Текст:
$text

Дай только перевод, без комментариев. Сохрани форматирование (абзацы, списки), если оно есть.
