# Исследование моделей AI для управления WordPress через инструменты

## 1. Какие модели подходят для tool calling

### Требования к модели

Для надёжной работы с инструментами (tool calling / function calling) модель должна:

| Требование | Описание | Важность |
|------------|----------|----------|
| **Tool Calling** | Поддержка API-параметра `tools` с описанием функций | Критично |
| **Multi-step reasoning** | Способность выполнять цепочки из 3+ шагов | Критично |
| **Низкий rate limit** | Минимум 10 запросов в минуту | Важно |
| **Низкий latency** | Ответ за < 5 секунд | Важно |
| **JSON parsing** | Корректный парсинг аргументов инструментов | Критично |
| **Instruction following** | Точное следование системному промпту | Критично |
| **Context window** | Минимум 8K токенов (лучше 32K+) | Важно |
| **Низкая стоимость** | $0.1-0.5 за 1M токенов | Желательно |

### Рейтинг моделей для WordPress управления

#### Бесплатные модели (ограничены rate limit)

| Модель | Provider | Tool Calling | Multi-step | Rate Limit | Вердикт |
|--------|----------|:------------:|:----------:|:----------:|---------|
| `cohere/north-mini-code:free` | Cohere | Частичный | ❌ | ~1/мин | ❌ Не подходит |
| `meta-llama/llama-3.1-8b-instruct:free` | Meta | Частичный | ❌ | ~1/мин | ❌ Недоступна |
| `google/gemini-flash-1.5:free` | Google | ✅ | ⚠️ | ~10/мин | ⚠️ Ограниченно |
| `mistralai/mistral-7b-instruct:free` | Mistral | ❌ | ❌ | ~5/мин | ❌ Не подходит |

**Вывод:** Бесплатные модели НЕ подходят для управления WordPress. Rate limit делает многошаговые задачи невозможными.

#### Дешёвые модели (оптимальный выбор)

| Модель | Provider | Tool Calling | Multi-step | Стоимость | Вердикт |
|--------|----------|:------------:|:----------:|:---------:|---------|
| `openai/gpt-4o-mini` | OpenAI | ✅ | ✅ | $0.15/1M | ✅ **Лучший выбор** |
| `anthropic/claude-3-haiku` | Anthropic | ✅ | ✅ | $0.25/1M | ✅ Отлично |
| `google/gemini-flash-1.5` | Google | ✅ | ✅ | $0.075/1M | ✅ Самый дешёвый |
| `meta-llama/llama-3.1-8b-instruct` | Meta | ⚠️ | ⚠️ | $0.1/1M | ⚠️ Средне |
| `deepseek/deepseek-chat` | DeepSeek | ✅ | ✅ | $0.14/1M | ✅ Хорошо |

**Вывод:** `openai/gpt-4o-mini` — лучшее соотношение цена/качество для tool calling.

#### Мощные модели (для сложных задач)

| Модель | Provider | Tool Calling | Multi-step | Стоимость | Вердикт |
|--------|----------|:------------:|:----------:|:---------:|---------|
| `openai/gpt-4o` | OpenAI | ✅ | ✅ | $2.5/1M | ✅ Топ качество |
| `anthropic/claude-3.5-sonnet` | Anthropic | ✅ | ✅ | $3/1M | ✅ Топ качество |
| `google/gemini-pro-1.5` | Google | ✅ | ✅ | $1.25/1M | ✅ Хорошо |

### Стоимость для AIWP (72 инструмента)

Расчёт для типичной сессии (10 сообщений, каждый вызов 3-5 итераций):

| Модель | Токены/запрос | Стоимость 10 запросов | Месяц (300 запросов) |
|--------|:-------------:|:---------------------:|:--------------------:|
| `cohere/north-mini-code:free` | ~8K | $0 (rate limit) | ❌ Невозможно |
| `gpt-4o-mini` | ~8K | ~$0.012 | ~$0.36 |
| `claude-3-haiku` | ~8K | ~$0.020 | ~$0.60 |
| `gemini-flash-1.5` | ~8K | ~$0.006 | ~$0.18 |
| `gpt-4o` | ~8K | ~$0.20 | ~$6.00 |

---

## 2. Терминология

### Основные понятия

| Термин | Описание | Как используется в AIWP |
|--------|----------|------------------------|
| **Tool Calling** | Возможность модели вызывать внешние функции | API-параметр `tools` в запросе |
| **Function Calling** | Синоним tool calling (OpenAI термин) | `function` в schema инструмента |
| **Tool Choice** | Стратегия выбора инструмента | `auto`, `required`, `none` |
| **Tool Result** | Результат выполнения инструмента | `role: "tool"` в messages |
| **Tool Schema** | JSON Schema описания инструмента | `parameters` в определении |
| **Agentic Loop** | Цикл: запрос → tool call → результат → следующий запрос | `process_message()` в class-chat.php |
| **System Prompt** | Инструкции для модели | `get_system_prompt()` |
| **Hallucination** | Модель придумывает несуществующие инструменты | Обнаружение + исправление |

### Формат tool call (OpenAI API)

```json
{
  "model": "gpt-4o-mini",
  "messages": [
    {"role": "system", "content": "Ты AI-агент..."},
    {"role": "user", "content": "Создай страницу"}
  ],
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "wp_create_page",
        "description": "Create a WordPress page",
        "parameters": {
          "type": "object",
          "properties": {
            "title": {"type": "string", "description": "Page title"},
            "content": {"type": "string", "description": "HTML content"}
          },
          "required": ["title"]
        }
      }
    }
  ],
  "tool_choice": "auto"
}
```

### Формат tool result

```json
{
  "role": "tool",
  "tool_call_id": "call_abc123",
  "content": "{\"success\":true,\"page_id\":42,\"url\":\"https://site.com/about/\"}"
}
```

---

## 3. Архитектура AIWP: Agentic Loop

### Цикл обработки сообщения

```
Пользователь: "Создай страницу О нас"
         │
         ▼
┌─────────────────────┐
│  1. System Prompt    │  Инструкции + контекст сайта
│  + User Message      │
│  + Tools Schema      │  72 инструмента
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  2. API Call #1      │  Отправка в OpenRouter
│     (with tools)     │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  3. Parse Response   │  AI вызывает wp_create_page
│     tool_call:       │  с аргументами
│     wp_create_page   │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  4. Execute Tool     │  wp_insert_post() → page_id=42
│     PHP execution    │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  5. API Call #2      │  Отправляем результат обратно
│     (with result)    │  AI формирует ответ
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  6. User Response    │  "Страница создана! ID: 42"
└─────────────────────┘
```

### Защита от галлюцинаций

```
AI вызывает "execute_bash" (несуществующий инструмент)
         │
         ▼
┌─────────────────────────────┐
│  Перехват: tool name not    │
│  in valid_tool_names[]      │
└─────────┬───────────────────┘
          │
          ▼
┌─────────────────────────────┐
│  System message:            │
│  "ОШИБКА: Ты вызвал         │
│   несуществующие инструменты│
│   Доступные: wp_*, aiwp_*"  │
└─────────┬───────────────────┘
          │
          ▼
┌─────────────────────────────┐
│  Повторный запрос с правиль- │
│  ными именами               │
└─────────────────────────────┘
```

---

## 4. MCP (Model Context Protocol) — альтернативный подход

### Что такое MCP

MCP — открытый протокол от Anthropic для подключения AI к внешним системам. Как USB-C для AI.

### Текущая архитектура AIWP vs MCP

| Аспект | AIWP (текущая) | MCP |
|--------|----------------|-----|
| Инструменты | OpenAI-compatible `tools` parameter | MCP Server |
| Протокол | HTTP REST (OpenRouter) | JSON-RPC over stdio/SSE |
| Клиент | WordPress plugin | MCP Client (Claude, ChatGPT) |
| Обнаружение | Фиксированный список | Динамическое (list_tools) |
| Транспорт | HTTP | stdio, SSE, Streamable HTTP |

### MCP для WordPress — возможная архитектура

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   AI Client      │     │   MCP Server     │     │   WordPress      │
│   (Claude/ChatGPT│◄───►│   (WordPress     │◄───►│   REST API       │
│    /MiMoCode)    │ MCP │    Plugin)       │     │   + WP-CLI       │
└──────────────────┘     └──────────────────┘     └──────────────────┘
```

### Преимущества MCP для WordPress

1. **Стандартизация** — один сервер работает с любым MCP-клиентом
2. **Динамическое обнаружение** — клиент автоматически видит доступные инструменты
3. **Безопасность** — OAuth 2.1 авторизация, sandbox
4. **Асинхронность** — Tasks extension для долгих операций
5. **Ресурсы** — доступ к файлам, БД, API без дублирования

### Реализация MCP Server для WordPress

```
aiwp-mcp-server/
├── src/
│   ├── index.ts          # Entry point
│   ├── server.ts         # MCP Server setup
│   ├── tools/
│   │   ├── content.ts    # wp_create_page, wp_get_posts...
│   │   ├── plugins.ts    # wp_install_plugin, wp_activate_plugin...
│   │   ├── themes.ts     # wp_get_themes, wp_switch_theme...
│   │   ├── settings.ts   # wp_get_option, wp_update_option...
│   │   └── aiwp.ts       # aiwp_analyze_site, aiwp_save_memory...
│   └── transport/
│       └── wordpress.ts  # WordPress REST API adapter
├── package.json
└── tsconfig.json
```

### Сравнение подходов

| Критерий | OpenAI Tools (текущий) | MCP |
|----------|:----------------------:|:---:|
| Простота реализации | ✅ | ⚠️ |
| Поддержка моделями | ✅ Все | ⚠️ Только MCP-клиенты |
| Динамические инструменты | ❌ | ✅ |
| Стандартизация | ⚠️ OpenAI-специфичный | ✅ Открытый стандарт |
| Безопасность | ⚠️ Базовая | ✅ OAuth 2.1 |
| Асинхронность | ❌ | ✅ Tasks |
| Множество клиентов | ⚠️ Через OpenRouter | ✅ Любой MCP-клиент |

---

## 5. Рекомендации для AIWP

### Краткосрочные (текущая архитектура)

1. **Заменить модель** по умолчанию на `openai/gpt-4o-mini`
2. **Добавить fallback** — если gpt-4o-mini недоступен, пробовать `claude-3-haiku`
3. **Увеличить rate limit** — текущий `cohere/north-mini-code:free` непригоден

### Среднесрочные (MCP интеграция)

1. **Создать MCP Server** как отдельный плагин/модуль
2. **Поддерживать оба протокола** — OpenAI Tools + MCP
3. **Предоставить CLI-клиент** для разработчиков

### Долгосрочные

1. **Полный переход на MCP** как основной протокол
2. **MCP Registry** — публикация сервера для сообщества
3. **MCP Apps** — интерактивный UI в Claude Desktop

---

## 6. Текущие ограничения и решения

### Проблема: Rate limit бесплатных моделей

**Решение:** Автоматический fallback на платные модели при rate limit.

### Проблема: Галлюцинация tool names

**Решение:** Перехват неизвестных имен + повтор с правильными (реализовано).

### Проблема: Многошаговые задачи

**Решение:** Agentic loop с retry + увеличенная задержка (реализовано).

### Проблема: Дублирование tools в system prompt

**Решение:** Убрано дублирование — tools передаются только через API parameter (реализовано).
