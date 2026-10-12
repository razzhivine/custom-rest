<?php

namespace Custom\Rest\Cli;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Security\Random;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Custom\Rest\Config;

/**
 * Создаёт вебхук для API: отдельного пользователя без прав администратора
 * и входящий вебхук от него только с правом пакета (scope из настроек).
 * Повторный запуск ничего не создаёт заново и печатает адрес существующего.
 *
 * Почему не от администратора: базовые методы REST (profile, user.admin)
 * отдают данные владельца вебхука, а поиск видит всё, что видит владелец.
 * Пароль пользователя случайный и нигде не нужен: вебхук работает без него.
 *
 * Запускается внутри Битрикса: `custom-rest webhook` (см. bin/custom-rest).
 */
final class Webhook
{
    private const LOGIN = 'rest-api';

    /**
     * @param bool $deactivateOthers отключить остальные активные вебхуки этого права и этого пользователя
     *
     * @return int код возврата для командной строки
     */
    public static function run(bool $deactivateOthers = false): int
    {
        if (!Loader::includeModule('rest')) {
            fwrite(STDERR, "ОШИБКА: модуль rest не установлен\n");

            return 1;
        }

        $scope = Config::scope();
        $title = 'REST API (' . $scope . ')';

        $userId = self::user();
        if ($userId === null) {
            return 1;
        }

        $hook = PasswordTable::getList([
            'select' => ['ID', 'PASSWORD'],
            'filter' => ['=USER_ID' => $userId, '=ACTIVE' => PasswordTable::ACTIVE, '=TITLE' => $title],
            'limit' => 1,
        ])->fetch();

        if (!$hook) {
            $hook = PasswordTable::createPassword($userId, [$scope], '', true);
            if (!$hook) {
                fwrite(STDERR, "ОШИБКА: не удалось создать вебхук\n");

                return 1;
            }
            PasswordTable::update($hook['ID'], ['TITLE' => $title, 'COMMENT' => 'Создан командой custom-rest webhook']);
            echo "Создан вебхук ID {$hook['ID']} с правом {$scope}\n";
        }

        // Вебхук должен иметь ровно одно право: лишние (iblock, rest, …) открывают стандартные методы в обход белых списков API.
        $scopes = array_column(PermissionTable::getList(['filter' => ['=PASSWORD_ID' => $hook['ID']]])->fetchAll(), 'PERM');
        if ($scopes !== [$scope]) {
            PermissionTable::deleteByPasswordId($hook['ID']);
            PermissionTable::add(['PASSWORD_ID' => $hook['ID'], 'PERM' => $scope]);
            echo "Права вебхука сведены к {$scope} (были: " . implode(', ', $scopes) . ")\n";
        }

        self::handleOthers((int) $hook['ID'], $userId, $scope, $deactivateOthers);

        echo "\nАдрес вебхука: /rest/{$userId}/{$hook['PASSWORD']}/\n"
            . "Секрет — это пароль: храните его на сервере (SSR, бэкенд), не в коде браузера.\n";

        return 0;
    }

    /**
     * ID служебного пользователя: существующий или новый (без групп).
     */
    private static function user(): ?int
    {
        $user = \CUser::GetByLogin(self::LOGIN)->Fetch();
        if ($user) {
            $userId = (int) $user['ID'];
            if (in_array(1, \CUser::GetUserGroup($userId))) {
                fwrite(STDERR, 'ОШИБКА: пользователь ' . self::LOGIN . " — администратор; уберите его из группы администраторов\n");

                return null;
            }

            return $userId;
        }

        // Почта служебная, писем на неё нет; если имя сервера не годится для адреса — заглушка.
        $email = self::LOGIN . '@' . preg_replace('/:\d+$/', '', (string) Option::get('main', 'server_name', ''));
        if (!check_email($email)) {
            $email = self::LOGIN . '@localhost.localdomain';
        }

        // Случайный пароль под любую политику паролей; входить им не нужно.
        $password = Random::getString(24, true) . 'aA1!';
        $cuser = new \CUser();
        $userId = (int) $cuser->Add([
            'LOGIN' => self::LOGIN,
            'NAME' => 'REST API',
            'EMAIL' => $email,
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
            'ACTIVE' => 'Y',
            'GROUP_ID' => [],
        ]);

        if ($userId <= 0) {
            fwrite(STDERR, 'ОШИБКА: пользователь ' . self::LOGIN . ': ' . strip_tags((string) $cuser->LAST_ERROR) . "\n");

            return null;
        }

        echo 'Создан пользователь ' . self::LOGIN . " (ID {$userId}), без групп и прав администратора\n";

        return $userId;
    }

    /**
     * Другие активные вебхуки с этим правом или от этого пользователя: сообщает о них или отключает.
     */
    private static function handleOthers(int $currentId, int $userId, string $scope, bool $deactivate): void
    {
        $others = PasswordTable::getList([
            'select' => ['ID', 'USER_ID', 'TITLE'],
            'filter' => [
                '=ACTIVE' => PasswordTable::ACTIVE,
                '!=ID' => $currentId,
                ['LOGIC' => 'OR', '=PERMISSION.PERM' => $scope, '=USER_ID' => $userId],
            ],
            'runtime' => [new Reference('PERMISSION', PermissionTable::class, ['=this.ID' => 'ref.PASSWORD_ID'])],
        ])->fetchAll();

        $seen = [];
        foreach ($others as $other) {
            if (isset($seen[$other['ID']])) {
                continue;
            }
            $seen[$other['ID']] = true;

            if ($deactivate) {
                PasswordTable::update($other['ID'], ['ACTIVE' => PasswordTable::INACTIVE]);
                echo "Отключён вебхук ID {$other['ID']} (пользователь {$other['USER_ID']}): включить обратно или удалить — в админке\n";
            } else {
                echo "Внимание: активен ещё вебхук ID {$other['ID']} (пользователь {$other['USER_ID']}). "
                    . "Отключите его (`custom-rest webhook --deactivate-others`) или удалите в админке\n";
            }
        }
    }
}
