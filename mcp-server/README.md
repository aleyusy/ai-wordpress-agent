# AIWP MCP Server

MCP Server для управления WordPress через Model Context Protocol.

## Установка

```bash
cd mcp-server
npm install
npm run build
```

## Конфигурация

1. Создайте Application Password в WordPress:
   - Админка → Пользователи → Ваш профиль
   - Прокрутите до "Application Passwords"
   - Введите имя (например "MCP Server")
   - Нажмите "Добавить"
   - Скопируйте пароль

2. Настройте переменные окружения:

```bash
export WP_URL=http://localhost
WP_USER=admin
WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

## Запуск

```bash
npm start
```

## Использование с Claude Desktop

Добавьте в `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "aiwp-wordpress": {
      "command": "node",
      "args": ["/path/to/aiwp/mcp-server/dist/index.js"],
      "env": {
        "WP_URL": "http://localhost",
        "WP_USER": "admin",
        "WP_APP_PASSWORD": "your-app-password"
      }
    }
  }
}
```

## Использование с VS Code (GitHub Copilot)

Добавьте в `.vscode/mcp.json`:

```json
{
  "servers": {
    "aiwp-wordpress": {
      "command": "node",
      "args": ["./mcp-server/dist/index.js"],
      "env": {
        "WP_URL": "http://localhost",
        "WP_USER": "admin",
        "WP_APP_PASSWORD": "your-app-password"
      }
    }
  }
}
```

## Доступные инструменты

### Контент
- `wp_get_pages` — список страниц
- `wp_create_page` — создать страницу
- `wp_update_page` — обновить страницу
- `wp_delete_page` — удалить страницу
- `wp_get_posts` — список постов
- `wp_create_post` — создать пост

### Медиа
- `wp_get_media` — библиотека медиа

### Пользователи
- `wp_get_users` — список пользователей

### Комментарии
- `wp_get_comments` — список комментариев

### Таксономии
- `wp_get_categories` — список категорий
- `wp_add_category` — создать категорию
- `wp_get_tags` — список тегов
- `wp_add_tag` — создать тег
- `wp_get_post_types` — типы записей
- `wp_get_taxonomies` — таксономии

### Настройки
- `wp_get_option` — получить опцию
- `wp_get_site_info` — информация о сайте
- `wp_get_custom_css` — получить CSS
- `wp_add_custom_css` — добавить CSS

### Поиск
- `wp_search_content` — поиск по контенту

## Безопасность

- Используйте Application Passwords (не основной пароль)
- Ограничьте права пользователя
- Не публикуйте MCP сервер в интернет без авторизации
