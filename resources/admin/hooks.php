<?php
/**
 * Вебхуки REST API в админке. Копия этого файла лежит в проекте как
 * local/admin/custom_rest_hooks.php (команда `custom-rest install`); правьте
 * его здесь, в пакете, и копируйте заново с --force.
 *
 * Подкомпоненты bitrix:rest.hook подключаются напрямую, а страницы
 * различаются параметром ?page=. Сам bitrix:rest.hook работает только
 * в режиме ЧПУ, которому нужно правило в /urlrewrite.php — вне /local.
 *
 * @global CUser $USER
 * @global CMain $APPLICATION
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Custom\Rest\Admin\Menu;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

// Страница лежит в /local, доступном из веба: без проверки любой
// авторизованный пользователь мог бы создавать вебхуки к REST API.
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}

$APPLICATION->SetTitle('Вебхуки REST API');

if (!Loader::includeModule('rest')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Модуль REST не установлен');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

$request = Application::getInstance()->getContext()->getRequest();
$page = (string) $request->getQuery('page');
$id = (int) $request->getQuery('id');

$listUrl = Menu::url() . '?lang=' . LANGUAGE_ID;
$apEditUrlTpl = Menu::url() . '?page=ap_edit&id=#id#&lang=' . LANGUAGE_ID;
$eventEditUrlTpl = Menu::url() . '?page=event_edit&id=#id#&lang=' . LANGUAGE_ID;

// Списки открывают редактирование через BX.SidePanel. Без правил слайдера
// он просто переходит по ссылке, но сам объект должен быть загружен.
CJSCore::Init(['sidepanel']);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

// PUBLIC => true: иначе CAdminContextMenu допишет /bitrix/admin/ перед
// любой ссылкой, которая не ведёт в /bitrix/admin/.
$menuItems = [];
if ($page !== '') {
    $menuItems[] = ['TEXT' => 'К списку', 'LINK' => $listUrl, 'ICON' => 'btn_list', 'PUBLIC' => true];
}
$menuItems[] = [
    'TEXT' => 'Входящий вебхук',
    'LINK' => str_replace('#id#', '0', $apEditUrlTpl),
    'ICON' => 'btn_new',
    'PUBLIC' => true,
];
$menuItems[] = [
    'TEXT' => 'Исходящий вебхук',
    'LINK' => str_replace('#id#', '0', $eventEditUrlTpl),
    'ICON' => 'btn_new',
    'PUBLIC' => true,
];

(new CAdminContextMenu($menuItems))->Show();

switch ($page) {
    case 'ap_edit':
        $APPLICATION->IncludeComponent('bitrix:rest.hook.ap.edit', '', [
            'EDIT_URL_TPL' => $apEditUrlTpl,
            'LIST_URL' => $listUrl,
            'ID' => $id,
            'SET_TITLE' => 'Y',
        ]);
        break;

    case 'event_edit':
        $APPLICATION->IncludeComponent('bitrix:rest.hook.event.edit', '', [
            'EDIT_URL_TPL' => $eventEditUrlTpl,
            'LIST_URL' => $listUrl,
            'ID' => $id,
            'SET_TITLE' => 'Y',
        ]);
        break;

    default:
        ?>
        <h2>Входящие вебхуки</h2>
        <?php
        $APPLICATION->IncludeComponent('bitrix:rest.hook.ap.list', '', [
            'EDIT_URL_TPL' => $apEditUrlTpl,
            'PAGE_SIZE' => 10,
        ]);
        ?>
        <h2>Исходящие вебхуки</h2>
        <?php
        $APPLICATION->IncludeComponent('bitrix:rest.hook.event.list', '', [
            'EDIT_URL_TPL' => $eventEditUrlTpl,
            'PAGE_SIZE' => 10,
        ]);
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
