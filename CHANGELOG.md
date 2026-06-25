# Changelog - AI WordPress Agent v2.0.7

## Дата: 25 июня 2026

---

## Исправленные баги (16)

### Критические
1. **trim_tools обрезал инструменты** (`class-ai.php:109`) — 72 инструмента обрезались до 30. Модель не видела многие инструменты.
2. **System prompt дублировал tool list** (`class-chat.php:361-366,445-447`) — описания 72 инструментов дублировались в system prompt AND в tools parameter. Промпт уменьшен с 7538 до 2539 символов.
3. **На 2-й итерации инструменты передавались пустыми** (`class-chat.php:87`) — после первого tool call AI не мог продолжить цепочку. Теперь передаёт $available_tools если есть role:'tool'.
4. **properties: [] вместо {}** (`class-tools.php:103-123`) — 14 инструментов имели пустой массив вместо объекта. OpenAI API возвращал 400 ошибку. Добавлена нормализация в get_for_ai().
5. **Галлюцинация tool names** (`class-chat.php:119`) — модель придумывала несуществующие имена (execute_bash, wordpress_create_or_update_post). Добавлен перехват + повтор с правильными именами.

### Средние
6. **wp_get_users — null roles** (`class-tools.php:1522`) — `$user->roles` мог быть null. Исправлено на `($user->roles ?? [])`.
7. **wp_get_post_types — undefined $pt->supports** (`class-tools.php:1459`) — заменено на `get_all_post_type_supports($slug)`.
8. **aiwp_save_skill — JSON не парсился** (`class-tools.php:1997`) — добавлен парсинг skill_data JSON.
9. **aiwp_search_memory — warning при не-массиве** (`class-memory.php:45`) — добавлена проверка `if (!is_array($entries)) continue`.
10. **Валидация required полей** (`class-tools.php:125`) — AI получает ошибку с подсказкой если не передал обязательный параметр.
11. **Rate limit без retry** (`class-ai.php:45-72`) — добавлен retry 5 раз с экспоненциальной задержкой (15/30/45/60 сек).
12. **"Provider returned error" на шаге 2** (`class-chat.php:98-107`) — форматирование tool results вместо ошибки API.
13. **JS: переменная r перезаписывалась** (`class-admin.php:155`) — DOM-элемент renamed в result_el.

### Мелкие
14. **format_tool_results — мало типов** (`class-chat.php:251-329`) — добавлено 13+ типов инструментов.
15. **message_needs_tools** (`class-chat.php:212`) — простые вопросы не отправляют инструменты.
16. **Context-aware tool filtering** (`class-tools.php:180-290`) — фильтрация инструментов по ключевым словам.

---

## Новые возможности (3)

### REST API endpoints
- `GET /wp-json/aiwp/v1/tools` — список всех инструментов
- `POST /wp-json/aiwp/v1/execute` — выполнение инструмента
- `POST /wp-json/aiwp/v1/chat` — чат с AI
- `GET /wp-json/aiwp/v1/site-info` — информация о сайте

### MCP Server
- Полноценный MCP Server в `mcp-server/`
- 50+ инструментов через Model Context Protocol
- Совместим с Claude Desktop, VS Code, MiMoCode
- Документация в `mcp-server/README.md`

### Исследование моделей
- Документация `docs/MODEL-RESEARCH.md`
- Сравнение моделей для tool calling
- Рекомендации по выбору модели

---

## Оптимизация токенов

| Метрика | До | После |
|---------|-----|-------|
| Токенов за запрос | 6314 | 305 |
| Стоимость/месяц | $0.35 | $0.055 |
| System prompt | 7538 chars | 2539 chars |

### Методы оптимизации
1. **Контекстная фильтрация** — AI получает только релевантные инструменты (4-10 вместо 72)
2. **Компактные параметры** — убраны лишние schema поля
3. **Умный пропуск** — простые вопросы не отправляют инструменты
4. **Убрано дублирование** — tool descriptions только в tools parameter

---

## Тестирование

### REST API: 40/40 (100%)
- READ инструменты: 20/20
- AIWP инструменты: 12/12
- WRITE операции: 8/8

### Chat flow: 8/8 (100%)
- Создание страниц
- Удаление дублей
- Улучшение дизайна (CSS)
- Информация о сайте

### Tool filtering: 18/18 (100%)
- Все категории инструментов корректно фильтруются

---

## Файлы

### Изменённые
- `includes/class-ai.php` — retry logic, rate limit handling
- `includes/class-chat.php` — process_message, format_tool_results, message_needs_tools
- `includes/class-tools.php` — get_for_ai, get_for_ai_compact, get_for_ai_filtered, required validation
- `includes/class-admin.php` — JS fix
- `includes/class-memory.php` — warning fix

### Новые
- `mcp-server/` — MCP Server (Node.js/TypeScript)
- `docs/MODEL-RESEARCH.md` — Исследование моделей
- REST API endpoints в class-main.php

---

## Совместимость

- WordPress 5.8+
- PHP 7.4+
- OpenRouter API
- OpenAI API (через OpenRouter)
- MCP Protocol (для Claude Desktop, VS Code)
