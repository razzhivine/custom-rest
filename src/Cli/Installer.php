<?php

namespace Custom\Rest\Cli;

/**
 * Копирует в проект файлы, которые должны лежать в нём: образец настроек
 * и страницу вебхуков для админки. Не требует загруженного Битрикса.
 */
final class Installer
{
    /**
     * @param string $root корень сайта (папка с bitrix/ и local/)
     * @param bool   $force перезаписать существующие файлы
     */
    public static function run(string $root, bool $force = false): int
    {
        $package = dirname(__DIR__, 2);

        $targets = [
            [$package . '/config/custom-rest.php.dist', $root . '/local/php_interface/config/custom-rest.php', 'настройки API'],
            [$package . '/resources/admin/hooks.php', $root . '/local/admin/custom_rest_hooks.php', 'страница вебхуков в админке'],
        ];

        foreach ($targets as [$from, $to, $what]) {
            $relative = ltrim(substr($to, strlen($root)), '/');

            if (is_file($to) && !$force) {
                echo "Пропущено (уже есть): {$relative}\n";
                continue;
            }

            if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
                fwrite(STDERR, "ОШИБКА: не удалось создать папку для {$relative}\n");

                return 1;
            }

            if (!copy($from, $to)) {
                fwrite(STDERR, "ОШИБКА: не удалось скопировать {$what} в {$relative}\n");

                return 1;
            }
            echo "Создано: {$relative} ({$what})\n";
        }

        $init = $root . '/local/php_interface/init.php';
        $connected = is_file($init) && str_contains((string) file_get_contents($init), 'Custom\\Rest\\Bootstrap');
        if (!$connected) {
            echo "\nОсталось подключить пакет: добавьте в local/php_interface/init.php\n\n"
                . "    require_once \$_SERVER['DOCUMENT_ROOT'] . '/local/vendor/autoload.php';\n"
                . "    \\Custom\\Rest\\Bootstrap::init();\n\n";
        }

        echo "\nДальше: заполните local/php_interface/config/custom-rest.php (что открыть через API) и создайте вебхук: custom-rest webhook\n";

        return 0;
    }
}
