# REST API: пакет custom/rest

Методы для фронтенда и внешних систем: контент инфоблоков (клубы, тренеры, новости…) — элементы, разделы, дерево
разделов, хлебные крошки, форма фильтра со счётчиками; поиск по сайту с подсказками; веб-формы — получить форму и
отправить её; справочники из highload-блоков. Контент через API только читается; единственное, что API записывает, —
результаты веб-форм.

Код пакета — в `src/`:

| Файл | Что внутри |
|---|---|
| [`Bootstrap.php`](../src/Bootstrap.php) | подключение к проекту: `Bootstrap::init()` |
| [`Registry.php`](../src/Registry.php) | регистрация методов: имя метода → класс; префикс и право из настроек |
| [`Config.php`](../src/Config.php) | чтение настроек проекта: белые списки, префикс |
| [`Api/AbstractApi.php`](../src/Api/AbstractApi.php) | общее для всех модулей: обработка ошибок, ответы 400/404, разбор фильтра |
| [`Api/IblockApi.php`](../src/Api/IblockApi.php) | методы инфоблоков, кеш, `ETag` |
| [`Api/SearchApi.php`](../src/Api/SearchApi.php) | поиск и подсказки (модуль `search`) |
| [`Api/HlApi.php`](../src/Api/HlApi.php) | highload-блоки (модуль `highloadblock`) |
| [`Api/FormApi.php`](../src/Api/FormApi.php) | веб-формы и капча (модуль `form`) |
| [`Api/SitemapApi.php`](../src/Api/SitemapApi.php) | карта сайта: адреса, исключения, страницы |
| [`Support/Content.php`](../src/Support/Content.php) | файлы, SEO, свойства инфоблоков |
| [`Search/IndexRules.php`](../src/Search/IndexRules.php) | правило поискового индекса: скрытое неактивным разделом в индексе не хранится |
| [`Revalidate/Notifier.php`](../src/Revalidate/Notifier.php) | сигнал фронту об изменении контента |
| [`Admin/Menu.php`](../src/Admin/Menu.php) | пункт «Вебхуки REST API» в админке |
| [`Cli/`](../src/Cli) и [`bin/custom-rest`](../bin/custom-rest) | команды `install` и `webhook` |
| [`openapi.json`](openapi.json) | описание API в формате OpenAPI 3.0; собирает `tools/openapi.py` |

Что открыто через API — белые списки инфоблоков, форм и highload-блоков, префикс методов — задаёт
проект в `local/php_interface/config/custom-rest.php` (образец — [`config/custom-rest.php.dist`](../config/custom-rest.php.dist),
см. [Для разработчика](#для-разработчика)).

**Префикс методов.** В документации методы пишутся как `custom.rest.element.get`: `custom.rest` — префикс по умолчанию.
В проекте его меняет ключ `prefix` в настройках (например, `'prefix' => 'shop'` даёт `shop.element.get`); так же
называется право (scope) вебхука, его можно задать отдельно ключом `scope`.

Модули Битрикса: `main`, `rest`, `iblock`, `search`, `form`, `highloadblock`.

## Содержание

- [Подключение](#подключение)
- [Запрос и ответ](#запрос-и-ответ)
- [Что отдаёт API, а что нет](#что-отдаёт-api-а-что-нет)
- [Общие параметры](#общие-параметры): `iblock_code`, раздел, `select`, `filter`, `order`, `properties`, пагинация
- [Общие объекты ответа](#общие-объекты-ответа): файл, SEO, свойства, хлебные крошки, `url`
- Методы: [`custom.rest.iblock.get`](#customrestiblockget) · [`custom.rest.element.get`](#customrestelementget) · [`custom.rest.element.list`](#customrestelementlist) ·
  [`custom.rest.section.get`](#customrestsectionget) · [`custom.rest.section.list`](#customrestsectionlist) ·
  [`custom.rest.section.tree`](#customrestsectiontree) · [`custom.rest.breadcrumbs.get`](#customrestbreadcrumbsget) ·
  [`custom.rest.filter.get`](#customrestfilterget)
- Поиск: [`custom.rest.search`](#customrestsearch) · [`custom.rest.search.suggest`](#customrestsearchsuggest)
- Веб-формы: [`custom.rest.form.get`](#customrestformget) · [`custom.rest.form.add`](#customrestformadd) ·
  [`custom.rest.captcha.get`](#customrestcaptchaget)
- Highload-блоки: [`custom.rest.hl.list`](#customresthllist) · [`custom.rest.hl.get`](#customresthlget)
- Карта сайта: [`custom.rest.sitemap.index`](#customrestsitemapindex) · [`custom.rest.sitemap.get`](#customrestsitemapget)
- [Описание API (OpenAPI)](#описание-api-openapi)
- [Ошибки](#ошибки)
- [Кеширование](#кеширование): в том числе `ETag` и `Last-Modified`
- [Сигнал фронту об изменении контента](#сигнал-фронту-об-изменении-контента)
- [Ограничения](#ограничения)
- [Для разработчика](#для-разработчика): инфоблок, поиск, веб-форма, новый метод
- [Тестовые данные](#тестовые-данные)

## Подключение

API работает через **входящий вебхук** Битрикса.

Проще всего командой пакета: она создаёт пользователя `rest-api` без групп и прав администратора и вебхук от него
только с правом пакета, а затем печатает адрес. Повторный запуск ничего не создаёт заново.

```bash
php local/vendor/bin/custom-rest webhook
```

Запускайте её тем же PHP и от того же пользователя, что и сайт (в Docker-стенде —
`docker compose exec -T -w /var/www/html -u www-data php php local/vendor/bin/custom-rest webhook`). Корень сайта ищется
вверх от текущей папки или берётся из переменной `BITRIX_ROOT`. С `--deactivate-others` команда ещё и отключает остальные
активные вебхуки этого права и этого пользователя (например, созданные раньше от администратора). Включить их обратно
или удалить — в админке.

Вручную:

1. Создайте **отдельного пользователя без прав администратора**, например «Сайт (API)».
2. В админке: **Настройки → Вебхуки REST API** (страница `/local/admin/custom_rest_hooks.php`, только для администраторов;
   создаётся командой `custom-rest install`).
3. «Входящий вебхук» от этого пользователя → в правах отметьте только право **`custom.rest`** (или ваш `scope`).
4. Сохраните и возьмите адрес вида `https://site.ru/rest/2/SECRET/`.

**Почему так.** Лишние права (`iblock`, `rest` и т. д.) открывают стандартные методы Битрикса в обход белых списков API.
Базовые методы (`profile`, `user.admin`) доступны любому вебхуку и рассказывают о его владельце, а поиск видит всё,
что видит владелец. Под владельцем вебхука сохраняются и результаты веб-форм — в админке будет видно, что заявка пришла
с сайта.

Адрес метода — адрес вебхука + имя метода + `.json`:

```
https://site.ru/rest/2/SECRET/custom.rest.element.list.json?iblock_code=clubs
```

> Секрет вебхука — это пароль. Не публикуйте его в браузерном коде: запросы с секретом делайте с сервера (SSR, бэкенд
> фронта), а наружу отдавайте уже готовые данные.

## Запрос и ответ

Параметры можно передать тремя способами — результат одинаковый:

```bash
# GET, строка запроса
curl 'https://site.ru/rest/2/SECRET/custom.rest.element.list.json?iblock_code=clubs&select[]=NAME&limit=5'

# POST, форма
curl -X POST 'https://site.ru/rest/2/SECRET/custom.rest.element.list.json' \
  --data-urlencode 'iblock_code=clubs' --data-urlencode 'filter[>=PROPERTY.AREA]=1000'

# POST, JSON — удобнее всего для фильтров
curl -X POST 'https://site.ru/rest/2/SECRET/custom.rest.element.list.json' \
  -H 'Content-Type: application/json' \
  -d '{"iblock_code": "clubs", "filter": {">=PROPERTY.AREA": 1000}, "select": ["NAME"]}'
```

> **Кодирование ключей в GET.** Операторы фильтра стоят в ключе: `filter[>=PROPERTY.AREA]`. Символ `=` внутри ключа
> нужно закодировать (`filter[%3E%3DPROPERTY.AREA]=1000`), иначе PHP разрежет параметр по нему. `URLSearchParams`,
> `qs` и `curl --data-urlencode` делают это сами; в JSON-запросе вопроса нет вовсе.

Успешный ответ — объект `result` (плюс служебное `time` от Битрикса):

```json
{"result": {...}, "time": {"start": 1791539228.1, "duration": 0.02, ...}}
```

Ошибка — `error` и `error_description` с HTTP-статусом 400, 404 или 500, см. [Ошибки](#ошибки).

Во всех ответах:

- **ключи полей — в нижнем регистре**: `preview_text`, `iblock_section_id`;
- **ID, `iblock_id`, `iblock_section_id`, `sort` — числа**;
- **даты — ISO 8601**: `"2026-10-09T09:48:10+03:00"` (поле только с датой — `"2026-10-09"`);
- **текст** приходит вместе с типом одним объектом: `preview_text`, `detail_text`, `description` —
  `{"value": "…", "type": "html"}`, где `type` — `"html"` или `"text"`. HTML выводите как HTML, `text` — как обычный текст.
  Так же устроены текст свойства типа HTML, описание формы и заголовок вопроса формы.

## Что отдаёт API, а что нет

API отдаёт только то, что видно посетителю сайта. Эти условия добавляются к каждому запросу, и фильтром клиента их
не обойти (например, `filter[ACTIVE]=N` вернёт пустой список, а не черновики):

| Что | Правило |
|---|---|
| Инфоблоки | Только из белого списка (`iblocks` в настройках API). Остальные — 400 «Инфоблок недоступен» |
| Элементы | Активные; дата начала активности наступила, дата окончания — не прошла; основной раздел и все его родители активны. Элементы в корне инфоблока (без раздела) видны |
| Разделы | Активные, и все их родители активны. Скрытый раздел — 404, его подразделы тоже не видны |
| Свойства | Читать (`properties[]`) — только из `read_properties`. Фильтровать — `read_properties` и свойства с флагом «Показывать в умном фильтре» |
| Поля | Только из списка разрешённых полей (см. [`select`](#select)) |
| Поиск | Те же правила: только инфоблоки из `iblocks`, без скрытого разделами, с учётом дат активности. Модуль поиска к тому же фильтрует по правам владельца вебхука — поэтому он не должен быть администратором |
| Веб-формы | Только из белого списка (`forms` в настройках API). Служебные поля формы и выключенные вопросы не отдаются |
| Highload-блоки | Только блоки и поля из белого списка (`hlblocks` в настройках API); запись с выключенным полем активности (`active_field`) не видна |

## Общие параметры

### `iblock_code`

Обязателен во всех методах. Это **символьный код API** инфоблока (поле `API_CODE` в настройках инфоблока, только
латиница и цифры), а не обычный символьный код. Инфоблок должен быть в белом списке.

### Раздел: `section_id` / `section_code_path`

Где метод работает с разделом, его можно указать двумя способами:

| Параметр | Пример | Что это |
|---|---|---|
| `section_id` | `8` | ID раздела |
| `section_code_path` | `moscow/moscow-center` | Путь символьных кодов от корня инфоблока — то же, что `#SECTION_CODE_PATH#` в URL |

Путь удобен на фронте: его можно взять прямо из адреса страницы. Он ищется одним запросом и строго по вложенности
(`moscow-center` без `moscow/` не найдётся). Не найден или раздел скрыт — 404. Оба параметра сразу — 400.

### `select`

Какие поля отдать. Без `select` — набор по умолчанию у каждого метода. `ID` и `IBLOCK_ID` добавляются всегда.
Регистр не важен. Поле не из списка — 400.

| Сущность | Разрешённые поля |
|---|---|
| Элемент | `ID`, `IBLOCK_ID`, `IBLOCK_SECTION_ID`, `NAME`, `CODE`, `ACTIVE`, `SORT`, `XML_ID`, `TIMESTAMP_X`, `DATE_CREATE`, `ACTIVE_FROM`, `ACTIVE_TO`, `PREVIEW_TEXT`, `PREVIEW_PICTURE`, `DETAIL_TEXT`, `DETAIL_PICTURE` |
| Раздел | `ID`, `IBLOCK_ID`, `IBLOCK_SECTION_ID`, `NAME`, `CODE`, `ACTIVE`, `SORT`, `XML_ID`, `TIMESTAMP_X`, `PICTURE`, `DETAIL_PICTURE`, `DESCRIPTION` |

Поля для ссылок и крошек (`NAME`, `CODE`, `XML_ID`, `IBLOCK_SECTION_ID`) API выбирает само, но в ответ они попадают,
только если вы их запросили. `seo` и `url` приходят всегда.

### `filter`

Условия в формате D7-фильтра Битрикса: **ключ = оператор + поле**, значение — то, с чем сравнивать.

| Оператор | Значение | Пример |
|---|---|---|
| нет или `=` | равно; массив — любое из значений | `{"=CODE": "arbat"}`, `{"ID": [16, 17]}` |
| `!` или `!=` | не равно | `{"!ID": 16}` |
| `>`, `>=`, `<`, `<=` | больше / меньше | `{">=PROPERTY.AREA": 1000}` |
| `%` | содержит подстроку | `{"%NAME": "арб"}` |
| `@` / `!@` | входит / не входит в список | `{"@PROPERTY.FORMAT": ["premium", "standard"]}` |
| `><` / `!><` | между / не между (два значения) | `{"><PROPERTY.AREA": [900, 1200]}` |

Другие операторы D7 (`*`, `~`, `^`, `$`) не поддерживаются — 400.

**Числа.** У числовых полей (`ID`, `SORT`, `IBLOCK_SECTION_ID`, у highload-блока — число, привязка, файл) и свойств
(число, привязка к элементу или разделу) значение должно быть числом, иначе 400. Пустое значение — условие
«не заполнено»: `{"PROPERTY.AREA": ""}`.

Поля — из таблицы [`select`](#select) для этой сущности, а у элементов ещё и свойства: **`PROPERTY.<КОД>`**.

**Даты** в фильтре — в ISO 8601, как их отдаёт API (`2026-10-01`, `2026-10-01T10:00:00+03:00`), или в формате сайта
(`01.10.2026`). Неверная дата — 400. Список дат («одно из») ядро Битрикса не поддерживает — задавайте диапазон через
`>=` и `<=`.

Условия на верхнем уровне объединяются через И. Для ИЛИ — вложенная группа с `LOGIC` (до 5 уровней вложенности):

```json
{"filter": {
  "%NAME": "ев",
  "0": {"LOGIC": "OR", "PROPERTY.FORMAT": "premium", "<PROPERTY.AREA": 1200}
}}
```

Свойства в фильтре:

| Тип свойства | С чем сравнивается |
|---|---|
| Список | **XML_ID значения** — то же, что API отдаёт в `value` (`"premium"`) |
| Число | число (`>=`, `<` и т. д. сравнивают как числа) |
| Строка | строка; у множественного — любое из значений |
| Справочник (highload-блок) | `UF_XML_ID` записи (`"pool"`) |
| Привязка к элементу / разделу | ID |
| Файл, HTML | фильтровать нельзя |

Фильтровать можно по свойствам из `read_properties` и по свойствам с флагом «Показывать в умном фильтре» (для
`element.list` с разделом — с учётом настроек этого раздела). Остальные — 400. Фильтр по множественному свойству
не задваивает элементы, `total` считается правильно.

**Отрицание по свойству** (`!`, `!=`, `!@` и т. п.) значит «у элемента **нет** такого значения»:
`{"!PROPERTY.AMENITIES": "pool"}` — клубы без бассейна, включая клубы, у которых удобства не заполнены вовсе. Клуб с
`["pool", "sauna"]` не подходит, хотя у него есть и другое значение. Так же работает отрицание по множественным полям
highload-блоков (`!UF_TAGS`). Отрицание без значения — `{"!PROPERTY.FEATURES": ""}` — «свойство заполнено».

### `order`

Сортировка: `{"поле": "ASC" | "DESC"}`, можно несколько полей. Поля — как в `select`, а у элементов ещё
`PROPERTY.<КОД>` для **немножественных** свойств из `read_properties` (список сортируется по сортировке значения в
админке). Направление — только `ASC` или `DESC`.

### `properties`

Свойства элемента: `properties[]=FORMAT&properties[]=AREA` или `"properties": ["FORMAT", "AREA"]`. Регистр не важен.
Только из `read_properties` — иначе 400 «Свойство недоступно». Формат значений — в
[Свойства элемента](#свойства-элемента).

### Пагинация: `page`, `limit`

У списков: `page` — номер страницы с 1, `limit` — элементов на странице. В ответе `meta`:

```json
{"total": 23, "page": 2, "limit": 10, "pages": 3}
```

## Общие объекты ответа

### Файл

```json
{
  "id": 98,
  "src": "/upload/iblock/ecd/9ox2ystjre2t66u2lz58w13yxsbkt2js.png",
  "full_path": "https://site.ru/upload/iblock/ecd/9ox2ystjre2t66u2lz58w13yxsbkt2js.png",
  "width": 640, "height": 400, "size": 1995,
  "alt": "Фото клуба Арбат", "title": "Арбат"
}
```

- `full_path` строится по «URL сайта» из настроек главного модуля, а не по адресу запроса.
- `alt` и `title` — у картинок элемента и раздела: из SEO-шаблонов инфоблока, если заданы, иначе описание файла или
  название. У файлов из свойств их нет.
- `width` и `height` у не-картинок — 0. Нет файла — вместо объекта `null`.

### SEO

Вычисленные значения SEO-шаблонов инфоблока (вкладка «SEO» в настройках инфоблока, раздела, элемента):

- в детальных методах (`element.get`, `section.get`) — `{"title", "description", "keywords", "h1"}`;
- в списках и дереве — только `{"h1"}`.

Шаблон не задан — `title` и `h1` равны названию, остальное пустое.

### Свойства элемента

Объект `properties`, ключи — коды свойств в нижнем регистре, в порядке запроса. Пустые свойства не приходят; если не
заполнено ни одно — `"properties": {}`. Множественное свойство — массив значений.

| Тип свойства | Значение | Пример |
|---|---|---|
| Список | `{"name", "value"}`, `value` — XML_ID значения (если пустой — ID) | `{"name": "Премиум", "value": "premium"}` |
| Число | число | `950.5` |
| Строка | строка | `"Бассейн"` |
| HTML/текст | `{"value", "type"}` | `{"value": "<p>Пн–Пт…</p>", "type": "html"}` |
| Файл | [Файл](#файл) без `alt`/`title` | |
| Привязка к элементу / разделу | ID — только видимые API (из открытых инфоблоков, активные, в активном разделе); ID скрытого в ответ не попадает | `13` |
| Справочник (highload-блок) | `UF_XML_ID` записи справочника | `"pool"` |

Свойство-справочник не раскрывается: название, иконку и другие поля записи берите из справочника через
[`custom.rest.hl.list`](#customresthllist). Справочники маленькие и меняются редко — удобнее загрузить справочник один раз,
закешировать на фронте и подставлять запись по `xml_id`, а не запрашивать его на каждой странице.

### `url`

Адрес страницы элемента или раздела на сайте — по шаблонам из настроек инфоблока («URL детальной страницы», «URL
страницы раздела»), как в стандартных компонентах. Работают все стандартные плейсхолдеры (`#SITE_DIR#`,
`#SECTION_CODE_PATH#`, `#ELEMENT_CODE#`, `#SECTION_ID#`…). Шаблон не задан — `null`.

### Хлебные крошки

Массив звеньев от главной до текущей страницы:

```json
[
  {"type": "home",    "id": null, "name": "Главная",  "code": null,            "url": "/"},
  {"type": "iblock",  "id": 5,    "name": "Клубы",    "code": "clubs",         "url": "/clubs/"},
  {"type": "section", "id": 7,    "name": "Москва",   "code": "moscow",        "url": "/clubs/moscow/"},
  {"type": "section", "id": 8,    "name": "Центр",    "code": "moscow-center", "url": "/clubs/moscow/moscow-center/"},
  {"type": "element", "id": 16,   "name": "Арбат",    "code": "arbat",         "url": "/clubs/moscow/moscow-center/arbat/"}
]
```

| `type` | Звено | `url` |
|---|---|---|
| `home` | Главная (всегда первое) | папка сайта, обычно `/` |
| `iblock` | инфоблок | «URL страницы списка» инфоблока |
| `section` | разделы от корня, только видимые | «URL страницы раздела» |
| `element` | текущий элемент (только для элемента) | «URL детальной страницы» |

Для элемента берётся **основной** раздел (`IBLOCK_SECTION_ID`). Элемент в корне инфоблока: главная → инфоблок →
элемент.

## Методы

| Метод | Что делает |
|---|---|
| [`custom.rest.iblock.get`](#customrestiblockget) | Сам инфоблок как страница списка: SEO, описание, адрес |
| [`custom.rest.element.get`](#customrestelementget) | Один элемент по ID или по коду |
| [`custom.rest.element.list`](#customrestelementlist) | Список элементов: раздел, фильтр, сортировка, страницы |
| [`custom.rest.section.get`](#customrestsectionget) | Один раздел по ID, коду или пути |
| [`custom.rest.section.list`](#customrestsectionlist) | Дочерние разделы, постранично |
| [`custom.rest.section.tree`](#customrestsectiontree) | Дерево разделов на несколько уровней (меню) |
| [`custom.rest.breadcrumbs.get`](#customrestbreadcrumbsget) | Только хлебные крошки |
| [`custom.rest.filter.get`](#customrestfilterget) | Форма фильтра: значения свойств и счётчики |
| [`custom.rest.search`](#customrestsearch) | Результаты поиска по сайту |
| [`custom.rest.search.suggest`](#customrestsearchsuggest) | Подсказки при вводе в строку поиска |
| [`custom.rest.form.get`](#customrestformget) | Веб-форма: поля, варианты ответов, валидаторы |
| [`custom.rest.form.add`](#customrestformadd) | Отправить веб-форму |
| [`custom.rest.captcha.get`](#customrestcaptchaget) | Капча для формы с включённой капчей |
| [`custom.rest.hl.list`](#customresthllist) | Записи highload-блока: фильтр, сортировка, страницы |
| [`custom.rest.hl.get`](#customresthlget) | Одна запись highload-блока по ID или `xml_id` |
| [`custom.rest.sitemap.index`](#customrestsitemapindex) | Какие карты есть и сколько в них страниц |
| [`custom.rest.sitemap.get`](#customrestsitemapget) | Адреса одной страницы карты сайта |

### `custom.rest.iblock.get`

Сам инфоблок как страница: корень каталога, `/clubs/`. Для заголовка, описания и `<title>` страницы списка, где у раздела
был бы `section.get`.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |

```json
{"result": {
  "id": 5, "api_code": "clubs", "code": "clubs", "name": "Клубы",
  "description": {"value": "Сеть фитнес-клубов в трёх городах.", "type": "text"},
  "picture": null,
  "seo": {"title": "Фитнес-клубы: Клубы", "description": "Список клубов сети: Клубы.", "keywords": "", "h1": "Все клубы сети"},
  "url": "/clubs/",
  "breadcrumbs": [{"type": "home", "id": null, "name": "Главная", "code": null, "url": "/"},
                  {"type": "iblock", "id": 5, "name": "Клубы", "code": "clubs", "url": "/clubs/"}]
}}
```

`seo` — шаблоны «Для разделов» на вкладке SEO инфоблока, вычисленные для самого инфоблока: так их использует штатный
компонент для страницы без раздела. Шаблон не задан — `title` и `h1` равны названию инфоблока, остальное пусто.
`description.type` — `text` или `html`: от него зависит, выводить описание как HTML. Кеш — как у остальных методов
инфоблоков; правки настроек инфоблока и SEO-шаблонов доходят с задержкой до часа.

### `custom.rest.element.get`

Один элемент со ссылкой и хлебными крошками.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |
| `id` или `code` | одно из двух | ID или символьный код элемента |
| `section_code_path` | | только с `code`: путь основного раздела элемента. Элемент ищется только в нём; пустая строка — в корне инфоблока. Неверный путь — 404 |
| `select` | | по умолчанию `ID`, `IBLOCK_ID`, `NAME`, `CODE`, `DATE_CREATE`, `DETAIL_PICTURE`, `DETAIL_TEXT` |
| `filter` | | дополнительные условия: не подошёл — 404 |
| `properties` | | свойства, см. [`properties`](#properties) |

Для страницы `/clubs/moscow/moscow-center/arbat/` передавайте `code` **вместе с** `section_code_path`. Символьный код
может повторяться в разных разделах, а путь делает поиск однозначным, и неверный URL сразу даёт 404 — у страницы один
адрес. Без пути по коду берётся первый элемент (по `SORT`, затем `ID`).

```json
{"iblock_code": "clubs", "code": "arbat", "section_code_path": "moscow/moscow-center",
 "select": ["NAME", "PREVIEW_TEXT", "DETAIL_PICTURE"], "properties": ["FORMAT", "AREA", "FEATURES", "SCHEDULE", "TRAINERS"]}
```

```json
{"result": {
  "id": 16,
  "name": "Арбат",
  "preview_text": {"value": "Клуб «Арбат» — коротко.", "type": "text"},
  "detail_picture": {"id": 98, "src": "/upload/iblock/…", "full_path": "https://…", "width": 640, "height": 400, "size": 1995, "alt": "Арбат", "title": "Арбат"},
  "iblock_id": 5,
  "seo": {"title": "Фитнес-клуб Арбат — Demo", "description": "Фитнес-клуб Арбат: расписание, тренеры, цены.", "keywords": "", "h1": "Арбат"},
  "properties": {
    "format": {"name": "Премиум", "value": "premium"},
    "area": 950.5,
    "features": ["Бассейн", "Сауна", "Парковка"],
    "schedule": {"value": "<p>Пн–Пт: 07:00–23:00<br>Сб–Вс: 09:00–21:00</p>", "type": "html"},
    "trainers": [13, 14, 15]
  },
  "url": "/clubs/moscow/moscow-center/arbat/",
  "breadcrumbs": [{"type": "home", …}, {"type": "iblock", …}, {"type": "section", …}, {"type": "section", …}, {"type": "element", …}]
}}
```

Ошибки: нет `id` и `code` или оба сразу — 400; элемент не найден или скрыт — 404 «Элемент с кодом 'arbat' не
найден»; раздел из `section_code_path` не найден — 404.

### `custom.rest.element.list`

Список элементов.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |
| `section_id` / `section_code_path` | | элементы раздела — те, у которых он **основной** |
| `include_subsections` | | `Y` — вместе с элементами всех видимых подразделов |
| `select` | | по умолчанию `ID`, `IBLOCK_ID`, `NAME`, `CODE`, `DATE_CREATE`, `PREVIEW_PICTURE`, `PREVIEW_TEXT` |
| `filter`, `order` | | по умолчанию сортировка `ID DESC` |
| `properties` | | |
| `page`, `limit` | | `limit` по умолчанию 10, максимум 50 |

У каждого элемента есть `url`. В `meta` — пагинация и `breadcrumbs`: до раздела, а без раздела — главная → инфоблок.

```json
{"iblock_code": "clubs", "section_code_path": "moscow", "include_subsections": "Y",
 "filter": {"PROPERTY.FORMAT": "premium"}, "order": {"PROPERTY.AREA": "DESC"},
 "select": ["NAME", "PREVIEW_PICTURE"], "limit": 12}
```

```json
{"result": {
  "items": [
    {"name": "Арбат", "preview_picture": {…}, "id": 16, "iblock_id": 5, "seo": {"h1": "Арбат"}, "url": "/clubs/moscow/moscow-center/arbat/"}
  ],
  "meta": {"total": 1, "page": 1, "limit": 12, "pages": 1, "breadcrumbs": [{"type": "home", …}, {"type": "iblock", …}, {"type": "section", "name": "Москва", …}]}
}}
```

Раздел не найден или скрыт — 404.

### `custom.rest.section.get`

Один раздел со ссылкой и хлебными крошками.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |
| `id`, `code_path` или `code` | одно из трёх | ID; путь кодов от корня (`moscow/moscow-center`) — для поиска по URL; символьный код — может повторяться в разных ветках, берётся первый по дереву |
| `select` | | по умолчанию `ID`, `IBLOCK_ID`, `IBLOCK_SECTION_ID`, `NAME`, `CODE`, `PICTURE`, `DETAIL_PICTURE`, `DESCRIPTION`, `SORT` |
| `filter` | | |

```json
{"iblock_code": "clubs", "code_path": "moscow"}
```

```json
{"result": {
  "id": 7, "iblock_id": 5, "iblock_section_id": null, "name": "Москва", "code": "moscow",
  "picture": {"id": 94, …, "alt": "Москва", "title": "Москва"},
  "detail_picture": null,
  "description": {"value": "Клубы: Москва", "type": "text"}, "sort": 100,
  "seo": {"title": "Фитнес-клубы: Москва", "description": "", "keywords": "", "h1": "Москва"},
  "url": "/clubs/moscow/",
  "breadcrumbs": [{"type": "home", …}, {"type": "iblock", …}, {"type": "section", "name": "Москва", …}]
}}
```

### `custom.rest.section.list`

Дочерние разделы одного уровня.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |
| `section_id` / `section_code_path` | | родитель; без него — разделы первого уровня |
| `select` | | как у `section.get` |
| `filter`, `order` | | по умолчанию `SORT ASC, NAME ASC` |
| `page`, `limit` | | `limit` по умолчанию 50, максимум 200 |

У каждого раздела есть `url`. В `meta` — пагинация и `breadcrumbs` до родителя.

### `custom.rest.section.tree`

Дерево разделов на несколько уровней одним запросом — для меню и каталога.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |
| `section_id` / `section_code_path` | | корень дерева (сам он в ответ не входит); без него — от корня инфоблока |
| `depth` | | сколько уровней вниз: по умолчанию 3, от 1 до 10 |
| `select` | | по умолчанию `ID`, `IBLOCK_ID`, `NAME`, `CODE`, `SORT` |
| `filter` | | |

Порядок — как в дереве инфоблока в админке; `order` не принимается (400). Скрытые разделы не попадают в дерево вместе
со всеми потомками. В ответе не больше 1000 разделов; если их больше, дерево обрезается и `meta.truncated` = `true`.

`filter` отсекает разделы вместе с поддеревом: раздел, чей родитель не прошёл фильтр, в дерево не входит.
`meta.total` — сколько разделов в дереве ответа.

```json
{"iblock_code": "clubs", "depth": 2}
```

```json
{"result": {
  "items": [
    {"id": 7, "name": "Москва", "code": "moscow", "sort": 100, "iblock_id": 5, "seo": {"h1": "Москва"}, "url": "/clubs/moscow/",
     "children": [
       {"id": 8, "name": "Центр", "code": "moscow-center", "sort": 100, "iblock_id": 5, "seo": {"h1": "Центр"}, "url": "/clubs/moscow/moscow-center/", "children": []}
     ]},
    {"id": 10, "name": "Санкт-Петербург", "code": "spb", "sort": 100, "iblock_id": 5, "seo": {"h1": "Санкт-Петербург"}, "url": "/clubs/spb/", "children": []}
  ],
  "meta": {"total": 3, "depth": 2, "truncated": false, "breadcrumbs": [{"type": "home", …}, {"type": "iblock", …}]}
}}
```

### `custom.rest.breadcrumbs.get`

Только хлебные крошки — когда сами данные страницы не нужны. В `element.get`, `section.get` и `meta` списков крошки
уже есть.

| Параметр | Описание |
|---|---|
| `iblock_code` | обязательный |
| `section_id` или `section_code_path` | крошки раздела (раздел — последнее звено) |
| `element_id` или `element_code` | крошки элемента (элемент — последнее звено); к `element_code` можно добавить `section_code_path`, как в `element.get` |

Нужен раздел или элемент; `section_id` вместе с элементом — 400.

```
custom.rest.breadcrumbs.get.json?iblock_code=clubs&element_code=nevsky&section_code_path=spb
```

```json
{"result": [
  {"type": "home", "id": null, "name": "Главная", "code": null, "url": "/"},
  {"type": "iblock", "id": 5, "name": "Клубы", "code": "clubs", "url": "/clubs/"},
  {"type": "section", "id": 10, "name": "Санкт-Петербург", "code": "spb", "url": "/clubs/spb/"},
  {"type": "element", "id": 18, "name": "Невский", "code": "nevsky", "url": "/clubs/spb/nevsky/"}
]}
```

### `custom.rest.filter.get`

Всё для формы подбора («умного фильтра»): какие свойства показать, их значения у видимых элементов и сколько
элементов останется при выборе каждого значения.

| Параметр | | Описание |
|---|---|---|
| `iblock_code` | обязательный | |
| `section_id` / `section_code_path`, `include_subsections` | | область, как у `element.list`: значения и счётчики считаются по ней |
| `properties` | | свойства формы в нужном порядке. Без него — все свойства с флагом «Показывать в умном фильтре» для раздела. Можно указать только свойства из `read_properties` или с этим флагом; файлы и HTML — нельзя |
| `filter` | | текущий выбор пользователя — **тот же фильтр, что вы передаёте в `element.list`** |

Как передать выбор:

| Что выбрано | Фильтр |
|---|---|
| одно значение | `{"PROPERTY.FORMAT": "premium"}` |
| несколько значений одного свойства (ИЛИ) | `{"@PROPERTY.FORMAT": ["premium", "standard"]}` |
| диапазон числа | `{">=PROPERTY.AREA": 1000, "<=PROPERTY.AREA": 1300}` |

```json
{"iblock_code": "clubs", "properties": ["FORMAT", "AREA", "TRAINERS"], "filter": {"PROPERTY.FORMAT": "premium"}}
```

```json
{"result": {
  "items": [
    {"code": "FORMAT", "name": "Формат", "type": "list", "multiple": false,
     "display_type": "checkbox", "expanded": true, "hint": "",
     "values": [
       {"value": "premium",  "name": "Премиум",  "count": 2, "selected": true},
       {"value": "standard", "name": "Стандарт", "count": 2, "selected": false}
     ]},
    {"code": "AREA", "name": "Площадь, м²", "type": "number", "multiple": false,
     "display_type": "range_slider", "expanded": false, "hint": "Общая площадь клуба",
     "range": {"min": 950.5, "max": 1402},
     "available": {"min": 950.5, "max": 1251.5},
     "selected": {"min": null, "max": null}},
    {"code": "TRAINERS", "name": "Тренеры", "type": "element", "multiple": true,
     "display_type": "select", "expanded": false, "hint": "",
     "values": [
       {"value": 13, "name": "Анна Смирнова", "count": 2, "selected": false},
       {"value": 14, "name": "Игорь Волков",  "count": 2, "selected": false},
       {"value": 15, "name": "Мария Ким",     "count": 2, "selected": false}
     ]}
  ],
  "meta": {"total": 2, "breadcrumbs": [...]}
}}
```

Поля свойства:

| Поле | Описание |
|---|---|
| `type` | `list`, `number`, `string`, `element` (привязка к элементу), `section` (привязка к разделу), `directory` (справочник) |
| `display_type` | как показывать, из настроек умного фильтра: `checkbox`, `checkbox_pictures`, `checkbox_pictures_names`, `radio`, `select`, `select_pictures_names`, `range`, `range_slider`, `calendar`. У свойства без флага — первый тип, доступный для его типа, как в админке |
| `expanded`, `hint` | «Развернуть» и «Подсказка» из настроек умного фильтра |
| `values` | у всех, кроме чисел: `value` — что передавать в фильтр, `name` — подпись, `count`, `selected` |
| `range` | у чисел: минимум и максимум у всех элементов области — границы ползунка |
| `available` | у чисел: минимум и максимум с учётом выбора в **других** свойствах |
| `selected` | у чисел: выбранный диапазон из фильтра (`>=`/`>` — от, `<=`/`<` — до) |

**Как считается `count`.** Сколько элементов останется, если выбрать это значение при текущем выборе в **остальных**
свойствах. Выбор в самом свойстве не учитывается — так видно, что даст каждый вариант. В примере выбран «Премиум», но у
«Стандарта» тоже 2: столько будет, если переключиться на него. Значения с `count: 0` не пропадают — сделайте их
неактивными, чтобы форма не прыгала.

Выбором в свойстве считаются только условия `PROPERTY.<КОД>` на верхнем уровне `filter`. Условия во вложенных группах
и по полям элемента (`%NAME` и т. п.) действуют на все счётчики.

**`meta.total`** — сколько элементов подходит под весь выбор, например для кнопки «Показать 2 клуба».

Значения берутся только у видимых элементов области. Свойство без значений в области в ответ не попадает. Привязки к
скрытым элементам и разделам не показываются. Значения списка — в порядке сортировки в админке, остальные — по
алфавиту.

Цикл работы формы:

1. Открыли страницу — `filter.get` без `filter`: строите форму.
2. Пользователь отметил значение — тот же `filter` уходит в `filter.get` (обновить счётчики) и в `element.list`
   (обновить список). Можно параллельно.

## Поиск

Поиск идёт по индексу модуля `search`: инфоблоки сами пишут туда элементы при сохранении. Что попадает в индекс,
настраивается в админке (см. [Для разработчика](#для-разработчика)).

### `custom.rest.search`

Результаты поиска по всем открытым инфоблокам одним списком, по релевантности, с морфологией: «бассейнами» найдёт
«бассейн».

| Параметр | | Описание |
|---|---|---|
| `q` | обязательный | поисковый запрос, до 200 символов |
| `iblock_code` | | массив кодов — искать только в этих инфоблоках из белого списка; без него — во всех |
| `page`, `limit` | | `limit` по умолчанию 10, максимум 50 |

```
custom.rest.search.json?q=бассейн&limit=10
```

```json
{"result": {
  "items": [
    {"type": "element", "iblock_code": "clubs", "id": 16, "name": "Арбат",
     "url": "/clubs/moscow/moscow-center/arbat/",
     "snippet": "Клуб «Арбат» — коротко. Клуб «Арбат» — подробное описание. <b>Бассейн</b> Сауна Парковка",
     "date": "2026-10-09T10:18:57+03:00"}
  ],
  "meta": {"total": 3, "page": 1, "limit": 10, "pages": 1}
}}
```

| Поле | Описание |
|---|---|
| `type` | `element` или `section` (разделы — если в инфоблоке включено «Индексировать разделы») |
| `snippet` | фрагмент текста с подсветкой совпадений — HTML с `<b>` |
| `url` | адрес по шаблонам инфоблока, как в остальном API |
| `date` | дата последнего изменения в индексе |

Порядок — как у штатного компонента: релевантность, затем свежие. Запрос, в котором после разбора не осталось слов
(одни стоп-слова или знаки, например «и»), — пустой результат с пояснением в `meta.notice`, а не ошибка. Опечатки поиск
не прощает: «басейн» не найдёт «бассейн».

### `custom.rest.search.suggest`

Подсказки для строки поиска при вводе: совпадения по началу слов в **названиях**.

| Параметр | | Описание |
|---|---|---|
| `q` | обязательный | что введено, до 200 символов |
| `iblock_code` | | как у `custom.rest.search` |
| `limit` | | по умолчанию 5, максимум 10 |

```
custom.rest.search.suggest.json?q=Нев
```

```json
{"result": {"items": [
  {"type": "element", "iblock_code": "clubs", "id": 18, "name": "Невский",
   "url": "/clubs/spb/nevsky/", "name_formatted": "<b>Нев</b>ский"}
]}}
```

`name_formatted` — название с подсвеченным совпадением (HTML с `<b>`). Выбрал подсказку — переход по `url`; нажал
Enter — страница результатов с `custom.rest.search`.

## Веб-формы

Формы модуля `form` («Сервисы → Веб-формы» в админке). Получить можно только формы из белого списка `forms`
в настройках API, по символьному идентификатору (SID). Работа с формой на фронте:

1. `custom.rest.form.get` — построить форму.
2. Если `use_captcha: true` — `custom.rest.captcha.get`, показать картинку и поле для кода.
3. `custom.rest.form.add` — отправить. Ошибки заполнения показать у полей; если в форме капча, перед повторной отправкой
   получить новую.

### `custom.rest.form.get`

| Параметр | | Описание |
|---|---|---|
| `sid` | обязательный | символьный идентификатор формы |

```
custom.rest.form.get.json?sid=TRIAL
```

```json
{"result": {
  "id": 3, "sid": "TRIAL", "name": "Записаться на пробное",
  "description": {"value": "Оставьте контакты — администратор клуба перезвонит в течение часа.", "type": "text"},
  "button": "Записаться", "use_captcha": false, "image": null,
  "fields": [
    {"sid": "NAME", "title": {"value": "Ваше имя", "type": "text"}, "required": true, "comment": "", "image": null,
     "controls": [{"type": "text", "name": "form_text_18", "label": "", "default": "", "multiple": false}],
     "validators": [{"name": "text_len", "params": {"length_from": 2, "length_to": 50}}]},
    {"sid": "CLUB", "title": {"value": "Клуб", "type": "text"}, "required": true, "comment": "", "image": null,
     "controls": [{"type": "dropdown", "name": "form_dropdown_CLUB", "multiple": false, "options": [
       {"value": 21, "name": "Арбат", "selected": true},
       {"value": 22, "name": "Невский", "selected": false}]}],
     "validators": []},
    {"sid": "TIME", "title": {"value": "Удобное время", "type": "text"}, "required": false, "comment": "", "image": null,
     "controls": [{"type": "checkbox", "name": "form_checkbox_TIME", "multiple": true, "options": [
       {"value": 27, "name": "Утро", "selected": false},
       {"value": 28, "name": "Вечер", "selected": false}]}],
     "validators": []}
  ]
}}
```

У вопроса формы в Битриксе может быть несколько ответов разных типов, поэтому у поля — массив `controls`:

| Тип | Что это | `name` |
|---|---|---|
| `text`, `textarea`, `email`, `url`, `password`, `hidden`, `date`, `file`, `image` | поле ввода — по одному на ответ; `label` — подпись, `default` — значение по умолчанию | `form_<тип>_<ID ответа>` |
| `radio`, `dropdown` | выбор одного из `options` | `form_<тип>_<SID вопроса>` |
| `checkbox`, `multiselect` | выбор нескольких из `options`, `multiple: true` | `form_<тип>_<SID вопроса>` |

- `name` — ключ, под которым значение передаётся в `custom.rest.form.add`.
- `options[]` — `value` (ID ответа, его и передают в `custom.rest.form.add`), `name` — подпись варианта, `selected` — вариант, выбранный по умолчанию.
- `validators` — валидаторы из админки с параметрами (`text_len`, `number`, `number_ext`, `date_ext`, `date_age`, `num_selected`, `file_size`, `file_type`,
  `image_size`), чтобы
  проверять ввод на фронте до отправки. Окончательная проверка всё равно на сервере.
- `title`, `description` — `{"value", "type"}`, `type` — `html` или `text`.

Кеша у `form.get` нет: правки формы в админке видны сразу.

### `custom.rest.form.add`

Отправка формы — как штатным компонентом: проверка обязательных полей, e-mail, дат, файлов, валидаторов и капчи,
сохранение результата со статусом формы по умолчанию, письма по почтовым шаблонам формы.

| Параметр | | Описание |
|---|---|---|
| `sid` | обязательный | символьный идентификатор формы |
| `values` | | значения по `name` из `custom.rest.form.get` |
| `captcha_sid`, `captcha_word` | для формы с капчей | `sid` из `custom.rest.captcha.get` и код, введённый пользователем |
| `hp` | | поле-ловушка для ботов: невидимое поле на фронте; если оно заполнено, ответ `success: true, result_id: 0`, но ничего не сохраняется |

| Поле | Значение |
|---|---|
| текстовое | строка |
| выбор одного | `value` варианта |
| выбор нескольких | массив `value` |
| файл | `["имя файла", "<содержимое в base64>"]` |

Ключи, которых нет в форме, игнорируются. Удобнее всего отправлять JSON-запросом:

```bash
curl -X POST 'https://site.ru/rest/2/SECRET/custom.rest.form.add.json' -H 'Content-Type: application/json' -d '{
  "sid": "TRIAL",
  "values": {
    "form_text_18": "Иван Петров",
    "form_dropdown_CLUB": 22,
    "form_checkbox_TIME": [27, 28],
    "form_file_50": ["spravka.pdf", "JVBERi0xLjQ…"]
  }
}'
```

Успех:

```json
{"result": {"success": true, "result_id": 5}}
```

Ошибки заполнения — не HTTP-ошибка, а ответ 200, чтобы показать их у полей:

```json
{"result": {"success": false, "errors": [
  {"field": "NAME", "message": "Ваше имя: слишком короткое значение"},
  {"field": "EMAIL", "message": "Ошибка! Введен некорректный адрес email"},
  {"field": "captcha", "message": "Неверно введены символы с картинки"}
]}}
```

`field` — SID вопроса, `captcha` или `null` (общая ошибка). Файл не в формате `["имя", "base64"]`, с неверным base64,
не из разрешённых расширений или больше лимита — ошибка этого поля. Файл проверяется до сохранения.

**Защита от спама.** Принимаются файлы только `pdf, jpg, jpeg, png, gif, webp, doc, docx, xls, xlsx, txt` не больше
5 МБ. Результатов одной формы сохраняется не больше 10 в минуту и 100 в час: сверх лимита — **429**. Лимит общий на
форму, а не на посетителя: вебхук вызывает ваш сервер, а не браузер посетителя. Если нужен лимит на посетителя, держите
его на стороне фронта или балансировщика. `custom.rest.captcha.get` выдаёт не больше 60 капч в минуту. Все значения
меняются ключом `form_limits` в настройках API.

Письма отправляет агент Битрикса — обычно в течение нескольких секунд, не мгновенно. Если в форме включены ограничения
на число заполнений одним пользователем, учтите: все заявки через API идут от одного пользователя — владельца вебхука.

### `custom.rest.captcha.get`

Капча Битрикса для формы с `use_captcha: true`. Код хранится на сервере, а не в сессии, поэтому капча работает с
фронтом на другом домене.

```json
{"result": {"sid": "0rl57yr7kbbtnxps23kxd0n21e64r00r",
  "src": "/bitrix/tools/captcha.php?captcha_sid=0rl57yr7kbbtnxps23kxd0n21e64r00r",
  "full_path": "https://site.ru/bitrix/tools/captcha.php?captcha_sid=0rl57yr7kbbtnxps23kxd0n21e64r00r",
  "width": 180, "height": 40}}
```

Покажите картинку `full_path`, а `sid` и введённый код передайте в `custom.rest.form.add`. **Код одноразовый:** сгорает
после любой отправки, удачной или нет — перед повторной отправкой нужна новая капча.

## Highload-блоки

Справочники и простые таблицы из модуля `highloadblock`: удобства, бренды, города и т. п. Доступны только блоки
из белого списка `hlblocks` в настройках API, по имени сущности (`NAME` блока в админке), и только поля
из списка этого блока.

### `custom.rest.hl.list`

| Параметр | | Описание |
|---|---|---|
| `hl` | обязательный | имя highload-блока |
| `select` | | поля из белого списка; без него — все; `ID` всегда |
| `filter`, `order` | | по полям из белого списка и `ID`; по умолчанию сортировка `UF_SORT` (если поле в списке), затем `ID` |
| `page`, `limit` | | `limit` по умолчанию 20, максимум 100 |

```
custom.rest.hl.list.json?hl=Amenities&filter[UF_TYPE]=water
```

```json
{"result": {
  "items": [
    {"id": 1, "uf_name": "Бассейн", "uf_xml_id": "pool", "uf_sort": 10, "uf_description": "Подогрев до 28 °C",
     "uf_icon": {"id": 144, "src": "/upload/uf/…", "full_path": "https://site.ru/upload/uf/…", "width": 640, "height": 400, "size": 1939},
     "uf_type": {"name": "Вода", "value": "water"},
     "uf_paid": false, "uf_since": "2026-01-01", "uf_tags": ["25 м", "6 дорожек"]}
  ],
  "meta": {"total": 1, "page": 1, "limit": 20, "pages": 1}
}}
```

Ключи — коды полей в нижнем регистре. Значения по типу поля:

| Тип поля | В ответе | В фильтре |
|---|---|---|
| Строка, адрес | строка | строка |
| Число целое / дробное | число | число |
| Да/нет | `true` / `false` | `true`/`false`, `Y`/`N`, `1`/`0` |
| Дата / дата и время | `2026-01-01` / ISO 8601 | ISO 8601 или формат сайта |
| Список | `{"name", "value"}`, `value` — XML_ID значения | XML_ID значения: `{"UF_TYPE": "water"}` |
| Файл | [Файл](#файл) без `alt`/`title` | — |
| Привязка к элементу, разделу, highload-блоку | ID | ID |
| Множественное поле | массив значений | любое из значений; записи не задваиваются |

Сортировать по множественным полям нельзя.

### `custom.rest.hl.get`

Одна запись по `id` или по `xml_id` — значению `UF_XML_ID`, по которому на справочник ссылаются свойства инфоблоков.

| Параметр | | Описание |
|---|---|---|
| `hl` | обязательный | имя highload-блока |
| `id` или `xml_id` | одно из двух | ID записи или `UF_XML_ID` |
| `select` | | как у `custom.rest.hl.list` |

```
custom.rest.hl.get.json?hl=Amenities&xml_id=sauna&select[]=UF_NAME&select[]=UF_TYPE
```

```json
{"result": {"id": 2, "uf_name": "Сауна", "uf_type": {"name": "Восстановление", "value": "recovery"}}}
```

Не найдена или выключена — 404; нет `id` и `xml_id` или оба — 400.

### Справочники в свойствах инфоблоков

Свойство типа «Справочник» отдаёт `UF_XML_ID` записи (см. [Свойства элемента](#свойства-элемента)). Как показать
название и иконку:

1. Один раз загрузить справочник целиком: `custom.rest.hl.list.json?hl=Amenities&limit=100` — и закешировать на фронте
   (в SSR — на минуты или часы).
2. Подставлять запись по `xml_id` из `properties` элемента.

Большой справочник (сотни записей и больше) — запрашивать только нужные записи:
`filter[@UF_XML_ID][]=pool&filter[@UF_XML_ID][]=sauna`.

Фильтр по свойству-справочнику в `element.list` работает по `xml_id`: `filter[PROPERTY.AMENITIES]=pool`, «любое из» —
`filter[@PROPERTY.AMENITIES][]=pool&filter[@PROPERTY.AMENITIES][]=sauna`, «без бассейна» — `filter[!PROPERTY.AMENITIES]=pool`.
В форме фильтра (`custom.rest.filter.get`) такое свойство имеет тип `directory`: `value` — `UF_XML_ID`, `name` и порядок — из
`UF_NAME` и `UF_SORT` справочника, записи с выключенным полем активности не выводятся. Подписи берутся, только если
highload-блок справочника открыт в `hlblocks` настроек API; иначе в `name` придёт сам `xml_id` — закрытый справочник
через форму фильтра не читается.

## Карта сайта

Адреса страниц для `sitemap.xml`, который собирает фронтенд: API отдаёт список, XML и домен добавляет фронт. В карту
входят страница списка инфоблока, его разделы и элементы (по шаблонам URL инфоблока, как `url` в остальных методах) и
статические страницы из настроек. Правила видимости те же, что у остальных методов: без неактивных, истёкших и будущих
публикаций и скрытых разделов, только инфоблоки из `iblocks`.

Если у инфоблока нет шаблона URL для списка, разделов или элементов, этого вида страниц в его карте не будет.

### `custom.rest.sitemap.index`

Какие карты есть. Одна на каждый инфоблок из белого списка плюс `static` для статических страниц из настроек. Фронт
строит по ним индекс `sitemap.xml` и файлы `sitemap-<name>-<page>.xml`. Параметров нет.

```json
{"result": {
  "items": [
    {"name": "clubs", "type": "iblock", "total": 214, "pages": 1, "lastmod": "2026-10-09T12:44:38+03:00"},
    {"name": "trainers", "type": "iblock", "total": 38, "pages": 1, "lastmod": "2026-10-08T09:10:00+03:00"},
    {"name": "static", "type": "static", "total": 2, "pages": 1, "lastmod": null}
  ],
  "meta": {"total": 3, "limit": 5000}
}}
```

`pages` — сколько страниц в карте при `limit` адресов на страницу (настройка `sitemap.page_size`, по умолчанию 5000,
не больше 50 000 — лимита одного `sitemap.xml`). `lastmod` — самая свежая дата изменения среди страниц карты.

### `custom.rest.sitemap.get`

Адреса одной страницы карты.

| Параметр | | Описание |
|---|---|---|
| `name` | обязательный | код инфоблока (`name` из `sitemap.index`) или `static` |
| `page` | | номер страницы, по умолчанию 1; за пределами — 404 |
| `images` | | `Y` — добавить в каждый адрес `images`: ссылки на картинки. По умолчанию выключено |

Порядок: страница списка инфоблока, затем разделы (в порядке дерева), затем элементы (по ID).

```json
{"result": {
  "items": [
    {"url": "/clubs/", "lastmod": "2026-10-09T12:44:38+03:00", "type": "iblock"},
    {"url": "/clubs/moscow/", "lastmod": "2026-10-09T12:44:38+03:00", "type": "section"},
    {"url": "/clubs/spb/nevsky/", "lastmod": "2026-10-09T12:44:38+03:00", "type": "element"}
  ],
  "meta": {"total": 3, "page": 1, "limit": 5000, "pages": 1}
}}
```

С `images=Y` у адресов есть `images`: `["https://site.ru/upload/iblock/….png"]` (картинки из `PREVIEW_PICTURE` и
`DETAIL_PICTURE` элемента, `PICTURE` и `DETAIL_PICTURE` раздела). Адреса страниц относительные, ссылки на картинки
абсолютные (по `base_url` или хосту сайта). `lastmod` — дата изменения элемента или раздела; у страницы списка — самая
свежая среди страниц карты. `type` — `iblock`, `section`, `element` или `static`.

**Статические страницы.** Главная, контакты и прочее, чего нет в инфоблоках, перечисляются в настройках API:

```php
'sitemap' => [
    'static' => ['/', ['url' => '/contacts/', 'lastmod' => '2026-01-15']],
],
```

### Как исключить страницы из карты

Исключения не прячут страницы в API (`element.get` по-прежнему отдаёт их), только убирают из карты. Три способа,
их можно сочетать; настраиваются в `sitemap.iblocks.<код инфоблока>`:

| Способ | Настройка | Как действует |
|---|---|---|
| Флаг у элемента | `exclude_property` — код свойства | любое непустое значение исключает элемент: заведите свойство «Не включать в карту сайта» и отмечайте его в админке |
| Флаг у раздела | `exclude_section_field` — код поля раздела | пользовательское поле раздела типа «Да/нет» (`UF_NO_INDEX`). Исключается раздел, **все его подразделы и все их элементы** |
| По коду | `exclude_sections`, `exclude_elements` — списки кодов | то же без правок контента; раздел из списка тоже уходит с подразделами и элементами |

Ещё можно убрать целый вид страниц у инфоблока: `list_page`, `sections` или `elements` равны `false`.

```php
'sitemap' => [
    'iblocks' => [
        'clubs' => [
            'exclude_property' => 'NO_INDEX',
            'exclude_section_field' => 'UF_NO_INDEX',
            'exclude_sections' => ['test'],
        ],
        'trainers' => ['sections' => false],
    ],
],
```

Свойство или поле, которого нет в инфоблоке, — ошибка конфигурации: ответ 500, подробности в логе.

## Описание API (OpenAPI)

Те же методы описаны для машин: [`openapi.json`](openapi.json) (OpenAPI 3.0). Из него можно получить:

- **интерактивную документацию**: загрузите файл в Swagger Editor или откройте в ReDoc, Postman, Insomnia;
- **типы для фронта**: `npx openapi-typescript docs/openapi.json -o api.d.ts` (или любой другой генератор клиента);
- **моки и проверки** ответов в тестах фронта.

Секрет вебхука в адресе, поэтому «Try it out» из браузера не годится: держите его на сервере. Фильтр и сортировка
описаны как объекты (`filter[CODE]=x`), списки — как `select[]=a&select[]=b`.

Файл собирает скрипт `tools/openapi.py` из описания в самом скрипте; руками `openapi.json` не правят. Запуск:
`python3 tools/openapi.py`, проверка без записи — `python3 tools/openapi.py --check`. Автотест `tests/run.sh`
следит, чтобы файл был свежим, а набор методов в нём совпадал с зарегистрированными в Битриксе.

## Ошибки

```json
{"error": "ERROR_ARGUMENT", "error_description": "Фильтрация по полю 'CREATED_BY' не разрешена."}
```

| HTTP | `error` | Когда |
|---|---|---|
| 400 | `ERROR_ARGUMENT` | неверные параметры: нет обязательного, инфоблок или форма не в списке, поле или свойство не разрешено, неверный `depth`/`order` и т. п. Текст в `error_description` можно показывать разработчику |
| 401 | `INVALID_CREDENTIALS` | неверный адрес вебхука или секрет |
| 401/403 | ошибка доступа Битрикса | у вебхука нет права `custom.rest` (вашего `scope`) — добавьте его в правах вебхука |
| 404 | `ERROR_NOT_FOUND` | элемент или раздел не найден, скрыт или не совпал путь |
| 429 | `TOO_MANY_REQUESTS` | слишком много отправок формы или запросов капчи за минуту / час |
| 500 | `ERROR_CORE` | внутренняя ошибка, в том числе не установлен нужный модуль. Подробности клиенту не отдаются — они в журнале исключений Битрикса (`exception_handling.log` в `.settings.php`), а если он не настроен — в логе PHP (в Docker-стенде — `docker compose logs php`, строки `[custom.rest]`) |

Ошибки заполнения веб-формы — не HTTP-ошибки: `custom.rest.form.add` отвечает 200 с `success: false` и списком ошибок
по полям.

## Кеширование

Ответы методов инфоблоков кешируются на час (`CACHE_TTL`) с тегами всех открытых инфоблоков — теми же, что у стандартных
компонентов. Тегами всех, а не одного, потому что ответ клуба зависит и от тренеров: скрыли тренера, и кеш клубов
сбрасывается тоже.
Поиск, веб-формы и highload-блоки не кешируются: запросы поиска произвольные, правки формы должны быть видны сразу,
а модуль highload-блоков не сбрасывает теги кеша при изменении записей (запросы к одной таблице дешёвые).

- **изменения в админке** (элементы, разделы, свойства, галочки умного фильтра) **сбрасывают кеш сразу**;
- после выкладки нового кода API или правки его настроек старые ответы не отдаются: в ключ кеша входит время изменения
  файлов API и настроек;
- в ключ кеша входят только параметры методов: лишние параметры запроса (`&_=123`) не создают новых записей кеша;
- ошибки не кешируются.

С задержкой до часа появятся:

- элементы, у которых наступила дата начала активности, и пропадут те, у которых прошла дата окончания;
- правки SEO-шаблонов, шаблонов URL и других настроек самого инфоблока.

### ETag и Last-Modified

Закешированные методы (инфоблоки, `filter.get`, `sitemap.*`) отдают заголовки:

```
ETag: W/"d952a8b42e902c9dbab8dc3a18b57efe"
Last-Modified: Fri, 09 Oct 2026 14:35:38 GMT
Cache-Control: private, no-cache
```

Клиент запоминает их и в следующем GET присылает `If-None-Match: <ETag>` (или `If-Modified-Since: <Last-Modified>`).
Если ответ не изменился, API отвечает **304 без тела**: берите ответ из своего кеша. Для каталога из тысяч записей это
ощутимая экономия трафика и времени.

- `ETag` — хеш самого ответа (без служебного `time`): тот же ответ даёт тот же ETag, даже когда кеш Битрикса пересобрался.
- `Last-Modified` — время, когда построен закешированный ответ: правка контента сбрасывает кеш и сдвигает его вперёд.
  Это не дата изменения конкретного элемента (для неё есть `lastmod` в `sitemap.get`).
- `If-None-Match` проверяется раньше `If-Modified-Since`.
- 304 только на прямой `GET`: в `batch` и при `POST` условные заголовки не действуют, ответ всегда полный.
- `Cache-Control: private, no-cache` значит «кешировать можно, но перед использованием спрашивать».
  Ответы с ошибками этих заголовков не получают.

Сбросить кеш вручную: «Настройки → Настройки продукта → Автокеширование → Очистка файлов кеша» или на стенде
очистка кеша в админке или команда вашей среды (в Docker-стенде — `bx cache-clear`).

## Сигнал фронту об изменении контента

Кеш ответов API живёт час, а у фронтенда свой кеш поверх. Чтобы правка в админке дошла до сайта за секунды, Битрикс
сам сообщает фронту, что изменилось: после правки элемента, раздела или записи справочника он отправляет POST на адрес
из настроек API, а фронт пересобирает затронутые страницы.

```php
'revalidate' => ['url' => 'https://site.ru/api/revalidate', 'secret' => 'длинная-случайная-строка', 'timeout' => 3],
```

Не задан `url` — уведомлений нет. Сообщается только о том, что открыто в API: инфоблоки из `iblocks` и highload-блоки из
`hlblocks`. Событие — добавление, изменение (в том числе значений свойств) и удаление элемента или раздела; для
справочника — добавление, изменение и удаление записи.

Тело запроса (JSON):

```json
{
  "sent_at": "2026-10-09T17:22:11+03:00",
  "events": [{"type": "element.changed", "iblock": "clubs", "id": 28}],
  "urls": ["/clubs/moscow/moscow-center/arbat/", "/clubs/moscow/moscow-center/", "/clubs/moscow/", "/clubs/"],
  "tags": ["iblock:clubs", "element:clubs:28"]
}
```

| Поле | Что внутри |
|---|---|
| `events` | что случилось: `element.changed`, `element.deleted`, `section.changed`, `section.deleted`, `hl.changed`; `iblock` или `hl` и `id` |
| `urls` | страницы, которые нужно пересобрать: сам элемент, его разделы вверх по цепочке (их списки содержат его) и страница списка инфоблока. Если у элемента сменился код, в списке и старый, и новый адрес |
| `tags` | метки для пересборки по тегу: `element:<инфоблок>:<id>`, `section:<инфоблок>:<id>`, `hl:<блок>` и `iblock:<инфоблок>` — любое изменение в инфоблоке. Метка `iblock:` нужна, когда одни страницы зависят от других (клуб показывает тренеров) или у раздела сменился код, и адреса потомков перечислить нельзя |

Несколько правок за один запрос (например, импорт) приходят одним уведомлением.

**Подпись.** Заголовки `X-Rest-Timestamp` (секунды) и `X-Rest-Signature: sha256=<HMAC-SHA256>`, где подписывается
строка `<Timestamp>.<тело>` секретом из настроек. Фронт обязан проверить подпись и отклонить запросы старше нескольких
минут. Пример на Node.js:

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

export function verify(req, rawBody, secret) {
  const ts = req.headers['x-rest-timestamp'];
  const sig = req.headers['x-rest-signature'] ?? '';
  if (Math.abs(Date.now() / 1000 - Number(ts)) > 300) return false;
  const expected = 'sha256=' + createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex');
  return sig.length === expected.length && timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}
```

**Как отправляется.** Не во время сохранения, а после того как админка получила ответ (фоновая задача Битрикса; из
консольных скриптов — на выходе из скрипта). К этому моменту кеш API уже сброшен, поэтому фронт, обратившись в API сразу,
получит свежее. Неудачный запрос повторяется один раз; сбои пишутся в журнал PHP (`[custom.rest.revalidate]`). Это
«лучшее из возможного», а не очередь: если фронт был недоступен, он узнает об изменении по истечении своего кеша.

**Справочники.** Названия значений в форме фильтра берутся из highload-блока и лежат в кеше API. Поэтому при изменении
записи блока из `hlblocks` кеш API сбрасывается всегда, даже без настройки `revalidate`.

## Ограничения

| Что | Значение |
|---|---|
| `element.list`: `limit` | по умолчанию 10, максимум 50 |
| `section.list`: `limit` | по умолчанию 50, максимум 200 |
| `section.tree`: `depth` | по умолчанию 3, максимум 10; не больше 1000 разделов |
| Вложенность групп в `filter` | до 5 уровней |
| Кеш | 1 час |
| Раздел элемента | везде учитывается только **основной** раздел (`IBLOCK_SECTION_ID`): видимость, крошки, `section_id` в списке. Элемент, привязанный ещё к другим разделам, в их списки не попадает |
| `search` / `search.suggest` | запрос до 200 символов; `limit` до 50 / до 10. Опечатки не прощаются — для этого нужен внешний движок (Elasticsearch, Meilisearch; Битрикс умеет Sphinx) |
| `sitemap.get` | `limit` (настройка `sitemap.page_size`) до 50 000 адресов; кеш на час с тегами всех открытых инфоблоков. Исключённый раздел уходит вместе с подразделами и элементами |
| `hl.list` | `limit` по умолчанию 20, максимум 100; сортировка только по немножественным полям |
| Даты в фильтре | ISO 8601 или формат сайта; список дат («одно из») не поддерживается — только диапазон |
| `form.add` | от имени владельца вебхука; не больше 10 результатов формы в минуту и 100 в час (429), файлы до 5 МБ и только разрешённых расширений; поле-ловушка `hp` и капча формы. Лимит на всю форму, не на посетителя |
| `captcha.get` | не больше 60 капч в минуту (429) |
| `filter.get` | по 1–2 запроса `GROUP BY` на каждое свойство. Для сотен и тысяч элементов с кешем это быстро; на десятках тысяч стоит перейти на фасетный индекс Битрикса |

## Для разработчика

### Как открыть инфоблок в API

1. **`API_CODE`.** В настройках инфоблока заполните «Символьный код API» — латиница и цифры, например `clubs`.
2. **Белый список.** Добавьте инфоблок в `iblocks` в `custom-rest.php` и перечислите свойства, которые можно
   читать:

   ```php
   'iblocks' => [
       'clubs' => [
           'read_properties' => ['FORMAT', 'AREA', 'FEATURES', 'SCHEDULE', 'PHOTOS', 'TRAINERS', 'AMENITIES'],
       ],
   ],
   ```

   Служебные свойства (заметки, контакты арендаторов, внутренние ID) в список не включайте.
3. **Шаблоны URL.** Вкладка «Инфоблок»: «URL страницы списка», «URL страницы раздела», «URL детальной страницы» —
   например `#SITE_DIR#/clubs/`, `#SITE_DIR#/clubs/#SECTION_CODE_PATH#/`,
   `#SITE_DIR#/clubs/#SECTION_CODE_PATH#/#ELEMENT_CODE#/`. Из них строятся `url` и крошки.
4. **Умный фильтр.** Включите в инфоблоке привязку свойств к разделам (поле `SECTION_PROPERTY`) — без неё флаг не
   работает. Затем в нужных свойствах отметьте «Показывать в умном фильтре», выберите тип отображения и при желании
   «Развернуть» и «Подсказку». Для отдельного раздела набор можно переопределить в его настройках.
5. **SEO.** Шаблоны на вкладке «SEO» инфоблока дают `seo` и `alt`/`title` картинок.
6. **Вебхук** с правом пакета (`custom.rest` или ваш `scope`) — см. [Подключение](#подключение).

### Как настроить поиск

В админке, у инфоблока: «Индексировать элементы для модуля поиска» (и «Индексировать разделы», если разделы тоже
нужно искать). У свойства: «Участвует в поиске» — тогда его значения ищутся вместе с текстом, например удобства
клуба. После включения на существующем контенте запустите переиндексацию: «Настройки → Поиск → Переиндексация».
Поиск работает только по инфоблокам из `iblocks` в настройках API.

Элементов и разделов из неактивных разделов в поисковом индексе нет: их убирает правило
[`IndexRules.php`](../src/Search/IndexRules.php), а при включении и выключении раздела индекс его
поддерева пересобирается. Правило действует на всё, что индексируется после выкладки, поэтому на уже существующем
индексе один раз выполните переиндексацию. Раньше API исключал скрытое списком ID в каждом запросе — на тысячах
элементов это росло бы вместе с каталогом.

### Как открыть веб-форму в API

1. Добавьте SID формы в `forms` в `custom-rest.php`.
2. У формы должен быть **статус по умолчанию** — без него результат не сохранится («Статусы» в настройках формы).
3. Для писем — почтовый шаблон формы (кнопка создания шаблона в настройках формы) и сайт формы, совпадающий с сайтом,
   на котором работает REST.
4. От спама — «Использовать CAPTCHA» в настройках формы.

### Как открыть highload-блок в API

Добавьте блок в `hlblocks` в `custom-rest.php`: ключ — имя сущности (`NAME`), `fields` — поля, которые
можно получать, фильтровать и сортировать, `active_field` — необязательное поле «да/нет», по которому запись скрыта.
Служебные поля (заметки, договоры, внутренние коды) в список не включайте.

```php
'hlblocks' => [
    'Amenities' => [
        'fields' => ['UF_NAME', 'UF_XML_ID', 'UF_SORT', 'UF_ICON', 'UF_TYPE'],
        'active_field' => 'UF_ACTIVE',
    ],
],
```

### Адрес сайта и ограничения форм

Два необязательных ключа `custom-rest.php`:

- `base_url` — `'https://site.ru'`: адрес для ссылок на файлы. Нужен, если HTTPS снимается на прокси и PHP видит запрос
  как http. Без него хост берётся из настроек сайта, протокол — из запроса.
- `form_limits` — `max_file_size`, `allowed_extensions`, `per_minute`, `per_hour`, `captcha_per_minute`. Значения по
  умолчанию описаны в разделе «Ограничения». 0 — без ограничения.

### Настройки стенда и сервера

`custom-rest.php` в git — одинаковый на стенде и на сервере. Рядом может лежать `custom-rest.local.php` с тем же
форматом, он не в git: его списки добавляются к основным. В нём живут тестовые инфоблоки, формы и блоки стенда,
поэтому на сервер они не попадают и вычищать их перед выкладкой не нужно. Пример для стенда —
`tests/fixtures/custom-rest.local.php.example`.

### Как добавить метод

Новый метод — публичный статический метод класса своего модуля в `src/Api/`, зарегистрированный в `Registry::METHODS`
(название без префикса → класс и метод). Методы другого модуля — отдельный класс-наследник `AbstractApi`. После
добавления впишите метод в `tools/openapi.py` и запустите `python3 tools/openapi.py` — иначе автотест `tests/run.sh`
скажет, что `openapi.json` устарел или метод не описан.

Тело метода оборачивайте в `self::run(...)` — единая обработка ошибок; у инфоблоков — в `self::cached(...)`: ещё кеш
и проверка инфоблока. Ошибки для клиента бросайте через `self::badRequest()` и `self::notFound()`. Любое другое
исключение превратится в 500 без подробностей и попадёт в лог.

## Тестовые данные и автотест

Для разработки пакета нужен сайт на Битриксе и работающий вебхук. В Docker-стенде этого проекта (пакет лежит в
`local/packages/custom-rest`, подключён через Composer) всё запускается одной командой:

```bash
bash local/packages/custom-rest/tests/run.sh --seed
```

`--seed` перед проверками пересоздаёт тестовые данные скриптом `tests/fixtures/seed.php` (целиком тип инфоблоков
`custom_rest_test`, поэтому его можно запускать повторно; ID при этом меняются) и создаёт
`local/php_interface/config/custom-rest.local.php` из `tests/fixtures/custom-rest.local.php.example`, чтобы тестовые
данные попали в белые списки. Без `--seed` проверяется то, что уже есть. Другое устройство сайта описано в шапке
`tests/run.sh` (переменные `PHP_RUN`, `WEBHOOK_CMD`, `REST_URL`, `REST_PREFIX`).

Скрипт `tests/run.sh` делает около ста десяти запросов: все методы, `ETag` и 304, уведомление фронту, фильтры и
отрицания, закрытое и скрытое (черновики, скрытые разделы, служебные поля), ошибки 400/404, защита форм и то, что
поиск следует за активностью раздела (на время проверки он выключает и включает раздел `spb`). Ничего не сохраняет.
Код возврата 0 — всё в порядке. После правок API запускайте его перед выкладкой.

Что создаёт скрипт тестовых данных:

- **`testclubs`** (инфоблок 1.0) — города и районы, включая скрытые разделы; клубы со свойствами всех типов;
  черновик, будущая и истёкшая публикации и клуб в скрытом разделе (API их отдавать не должен); SEO-шаблоны, шаблоны
  URL, свойства в умном фильтре; множественное свойство-справочник `AMENITIES` (ссылается на `TestAmenities`; у
  Невского среди значений выключенная запись `kids`); служебное свойство `INTERNAL_NOTE`, которого нет в `read_properties`;
  флаги исключения из карты сайта: свойство `NO_INDEX` у «Васильевского» и поле `UF_NO_INDEX` у раздела «Центр» (с ним
  уходят «Арбат» и «Тверская»);
- **`testtrainers`** (инфоблок 2.0, свойства в отдельных таблицах);
- **`testleads`** — «заявки» с телефоном; не в белом списке, проверка запрета;
- **веб-формы** `TEST_FEEDBACK` (поля всех типов, файл, валидатор, служебное поле и выключенный вопрос — API их не
  отдаёт), `TEST_CALLBACK` (с капчей) и `TEST_SECRET` (не в белом списке). У форм есть статус по умолчанию и почтовый
  шаблон; письма на стенде ловит Mailpit.

У свойства клубов «Удобства» включено «Участвует в поиске»: `custom.rest.search?q=бассейн` находит клубы с бассейном.

Highload-блоки: **`TestAmenities`** — справочник удобств с полями всех основных типов (строка, число, да/нет, дата,
список, файл-иконка, множественная строка), выключенной записью и служебным полем `UF_INTERNAL` вне белого списка —
API их не отдаёт; **`TestPartners`** — не в белом списке, проверка запрета.
