# Журнал изменений

## Не выпущено

- Метод `search.correct`: исправление опечаток в поисковом запросе по словарю индекса.
- Служебные поля веб-форм в `form.add`: параметр `service` и настройка `form_service_fields`.
- Пользовательские поля разделов (`UF_…`) в `section.get`, `section.list` и `section.tree`: настройка инфоблока `section_fields`.

## 1.0.0

Первый выпуск как отдельного пакета.

- Методы: `iblock.get`, `element.get/list`, `section.get/list/tree`, `breadcrumbs.get`, `filter.get`, `search`,
  `search.suggest`, `form.get`, `form.add`, `captcha.get`, `hl.list`, `hl.get`, `sitemap.index`, `sitemap.get`.
- Префикс методов и название права настраиваются (по умолчанию `custom.rest`).
- Кеш с тегами, `ETag` и `Last-Modified` с ответом 304.
- Защита форм: расширения и размер файлов, лимиты частоты, поле-ловушка, лимит капч.
- Исключения из карты сайта: свойство элемента, поле раздела, коды.
- Уведомления фронту об изменении контента с подписью HMAC.
- Описание в формате OpenAPI 3.0.
- Команды `custom-rest install` и `custom-rest webhook`.
