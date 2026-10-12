<?php

namespace Custom\Rest;

use Bitrix\Main\Loader;

/**
 * Настройки пакета: что открыто через API (белые списки), имя и префикс
 * методов, адрес фронта для уведомлений и т. д.
 *
 * Читаются из папки настроек проекта (по умолчанию local/php_interface/config):
 *   custom-rest.php        — основной файл, лежит в git;
 *   custom-rest.local.php  — необязательный, не в git: списки локального стенда
 *                            добавляются к основным.
 * Локальный файл дополняет основной: списки объединяются, у словарей
 * (iblocks, hlblocks, …) ключ из локального файла заменяет такой же ключ,
 * скалярные значения заменяются. Образец — config/custom-rest.php.dist пакета.
 */
class Config
{
    /** Подпапка кеша API внутри /bitrix/cache; теги — iblock_id_<ID> открытых инфоблоков. */
    public const CACHE_DIR = '/custom_rest';

    /** Префикс методов по умолчанию: custom.rest.element.get, … */
    public const DEFAULT_PREFIX = 'custom.rest';

    private const FILE = 'custom-rest.php';

    private const LOCAL_FILE = 'custom-rest.local.php';

    private static ?string $directory = null;

    private static ?array $config = null;

    /**
     * Задаёт папку настроек; null — по умолчанию. Вызывается из Bootstrap::init().
     */
    public static function configure(?string $directory): void
    {
        self::$directory = $directory !== null ? rtrim($directory, '/\\') : null;
        self::$config = null;
    }

    public static function directory(): string
    {
        return self::$directory ?? Loader::getDocumentRoot() . '/local/php_interface/config';
    }

    public static function get(string $key): array
    {
        $value = self::load()[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Строковая настройка (например, base_url); нет или не строка — ''.
     */
    public static function getString(string $key): string
    {
        $value = self::load()[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Префикс методов: <префикс>.element.get, <префикс>.search, … Ключ prefix
     * в настройках; только латиница и цифры, части через точку. Неверный
     * или пустой — custom.rest.
     */
    public static function prefix(): string
    {
        $prefix = self::getString('prefix');
        if ($prefix === '') {
            return self::DEFAULT_PREFIX;
        }

        if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/', $prefix)) {
            error_log("[custom.rest] Префикс методов '{$prefix}' недопустим, используется " . self::DEFAULT_PREFIX);

            return self::DEFAULT_PREFIX;
        }

        return $prefix;
    }

    /**
     * Название права (scope), которое выдаётся вебхуку. Ключ scope в
     * настройках; по умолчанию равно префиксу.
     */
    public static function scope(): string
    {
        $scope = self::getString('scope');

        return $scope !== '' ? $scope : self::prefix();
    }

    /**
     * Файлы настроек, которые есть: время их изменения входит в ключ кеша.
     */
    public static function files(): array
    {
        return array_values(array_filter(
            [self::directory() . '/' . self::FILE, self::directory() . '/' . self::LOCAL_FILE],
            'is_file'
        ));
    }

    private static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $config = [];
        foreach (self::files() as $file) {
            $part = include $file;
            if (!is_array($part)) {
                continue;
            }

            foreach ($part as $key => $value) {
                if (!is_array($value)) {
                    $config[$key] = $value;
                    continue;
                }

                $base = is_array($config[$key] ?? null) ? $config[$key] : [];
                $config[$key] = array_is_list($base) && array_is_list($value)
                    ? array_values(array_unique(array_merge($base, $value)))
                    : array_replace($base, $value);
            }
        }

        return self::$config = $config;
    }
}
