# custom/rest

Headless REST API для сайта на 1С-Битрикс: отдаёт фронтенду контент инфоблоков, поиск, веб-формы, справочники
highload-блоков и карту сайта. Подключается к любому проекту одной строкой, а что именно открыть, решает белый список
в настройках проекта.

- **Только публичное.** Черновики, неактивные, истёкшие и будущие публикации, скрытые разделы и служебные поля наружу не
  уходят. Всё, чего нет в белом списке, недоступно.
- **Методы:** элементы и разделы (`element.get/list`, `section.get/list/tree`, `iblock.get`), хлебные крошки,
  форма фильтра со счётчиками, поиск и подсказки, веб-формы с капчей, highload-блоки, карта сайта.
- **Для продакшена:** кеш с тегами, `ETag`/`304`, лимиты и защита форм, уведомления фронту об изменении контента,
  описание в формате OpenAPI.
- Работает через входящий вебхук Битрикса с единственным правом пакета — стандартные методы `iblock.*` не открываются.

Полное описание методов — [docs/REST-API.md](docs/REST-API.md), схема — [docs/openapi.json](docs/openapi.json).

## Требования

- PHP 8.1 и выше, расширения `json` и `mbstring`;
- 1С-Битрикс (проверялось на «Управление сайтом» 26.x; нужен модуль `rest`);
- модули Битрикса: `main`, `rest`, `iblock`; `search`, `form` и `highloadblock` — если используете поиск, формы и справочники.

## Установка

1. Подключите пакет в `local/composer.json` проекта и выполните `composer install`.

   Из GitHub (Packagist не нужен, достаточно репозитория):

   ```bash
   cd local
   composer config repositories.custom-rest vcs https://github.com/razzhivine/custom-rest
   composer require custom/rest:^1.0
   ```

   То же вручную в `local/composer.json`:

   ```json
   {
       "repositories": [{"type": "vcs", "url": "https://github.com/razzhivine/custom-rest"}],
       "require": {"custom/rest": "^1.0"}
   }
   ```

   Свежая разработка без релиза — `"custom/rest": "dev-main"`. Обновление: `composer update custom/rest`.

   Или из папки внутри проекта (разработка): `"repositories": [{"type": "path", "url": "packages/custom-rest",
   "options": {"symlink": true}}]`, `"require": {"custom/rest": "*@dev"}`.

2. Добавьте в `local/php_interface/init.php`:

   ```php
   require_once $_SERVER['DOCUMENT_ROOT'] . '/local/vendor/autoload.php';
   \Custom\Rest\Bootstrap::init();
   ```

3. Скопируйте в проект образец настроек и страницу вебхуков для админки:

   ```bash
   php local/vendor/bin/custom-rest install
   ```

   Команда создаёт `local/php_interface/config/custom-rest.php` и `local/admin/custom_rest_hooks.php`, существующие файлы
   не трогает (`--force` перезаписывает).

4. Откройте в `custom-rest.php` то, что нужно фронту: инфоблоки (по `API_CODE`), формы, highload-блоки.
   У инфоблока заполните «Символьный код API» и шаблоны URL.

5. Создайте вебхук (отдельный пользователь без прав администратора и только право пакета) и возьмите адрес:

   ```bash
   php local/vendor/bin/custom-rest webhook
   ```

   Запускайте команды тем же PHP и от того же пользователя, что и сайт. Секрет вебхука — пароль: держите его на сервере
   (SSR, бэкенд фронта), не в браузерном коде.

## Использование

```bash
curl 'https://site.ru/rest/2/SECRET/custom.rest.element.list.json?iblock_code=clubs&select[]=NAME&limit=5'
```

Имя метода — `<префикс>.<название>`. Префикс по умолчанию `custom.rest`; в проекте его меняет ключ `prefix` в
`custom-rest.php` (например, `'prefix' => 'shop'` даёт `shop.element.list`). Право вебхука называется так же и
настраивается ключом `scope`.

## Настройки

Файл `local/php_interface/config/custom-rest.php`, образец с пояснениями — [config/custom-rest.php.dist](config/custom-rest.php.dist).
Рядом может лежать `custom-rest.local.php` (не в git): его списки добавляются к основным, так тестовые данные стенда
не попадают на сервер.

## Разработка

Пакет проверяется на живом сайте с тестовыми данными:

```bash
bash tests/run.sh --seed     # пересоздать тестовые данные и прогнать ~110 проверок
python3 tools/openapi.py     # пересобрать docs/openapi.json после правки методов
```

Подробности и переменные окружения для другого устройства сайта — в шапке [tests/run.sh](tests/run.sh) и в
[docs/REST-API.md](docs/REST-API.md), раздел «Для разработчика».

## Лицензия

MIT, см. [LICENSE](LICENSE).
