<?php

namespace Custom\Rest;

use Custom\Rest\Admin\Menu;
use Custom\Rest\Revalidate\Notifier;
use Custom\Rest\Search\IndexRules;

/**
 * Подключение пакета к проекту: одна строка в local/php_interface/init.php
 * после подключения автозагрузчика Composer:
 *
 *   require_once __DIR__ . '/../vendor/autoload.php';
 *   \Custom\Rest\Bootstrap::init();
 *
 * Регистрирует REST-методы, обработчики событий (индекс поиска, уведомления
 * фронта) и пункт меню админки. Повторный вызов ничего не делает.
 *
 * Параметры (все необязательные):
 *   config_dir  — папка настроек вместо local/php_interface/config;
 *   admin_page  — адрес страницы вебхуков в админке (по умолчанию
 *                 /local/admin/custom_rest_hooks.php, создаётся командой install).
 */
final class Bootstrap
{
    private static bool $initialized = false;

    public static function init(array $options = []): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        Config::configure($options['config_dir'] ?? null);

        Registry::register();
        Menu::register($options['admin_page'] ?? Menu::DEFAULT_PAGE);
        IndexRules::register();
        Notifier::register();
    }
}
