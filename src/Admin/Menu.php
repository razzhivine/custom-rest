<?php

namespace Custom\Rest\Admin;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;

/**
 * Пункт «Вебхуки REST API» в разделе «Настройки» админки. Страница лежит в
 * проекте (её создаёт команда `custom-rest install`), потому что админка
 * Битрикса открывает только файлы из /bitrix/admin и /local/admin; пункт
 * показывается только администраторам и только если страница существует.
 */
class Menu
{
    public const DEFAULT_PAGE = '/local/admin/custom_rest_hooks.php';

    private static string $page = self::DEFAULT_PAGE;

    public static function register(string $page = self::DEFAULT_PAGE): void
    {
        self::$page = $page;

        EventManager::getInstance()->addEventHandler(
            'main',
            'OnBuildGlobalMenu',
            [self::class, 'onBuildGlobalMenu']
        );
    }

    public static function url(): string
    {
        return self::$page;
    }

    /**
     * Ядро подсвечивает активным только пункт со ссылкой из /bitrix/admin/,
     * поэтому на странице в /local он остаётся невыделенным — это ожидаемо.
     */
    public static function onBuildGlobalMenu(array &$globalMenu, array &$moduleMenu): void
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAdmin() || !is_file(Loader::getDocumentRoot() . self::$page)) {
            return;
        }

        $moduleMenu[] = [
            'parent_menu' => 'global_menu_settings',
            'section' => 'custom_rest_hooks',
            'sort' => 1800,
            'text' => 'Вебхуки REST API',
            'title' => 'Входящие и исходящие вебхуки REST API',
            'url' => self::$page . '?lang=' . LANGUAGE_ID,
            'icon' => 'sys_menu_icon',
            'items_id' => 'menu_custom_rest_hooks',
        ];
    }
}
