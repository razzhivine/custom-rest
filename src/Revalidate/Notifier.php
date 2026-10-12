<?php

namespace Custom\Rest\Revalidate;

use Custom\Rest\Api\IblockApi;
use Custom\Rest\Config;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Entity\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;

/**
 * Сообщает фронтенду, что контент изменился: после правки элемента, раздела
 * или записи справочника в админке отправляет POST на адрес из настроек API
 * (ключ revalidate), чтобы фронт пересобрал затронутые страницы, не дожидаясь
 * конца кеша.
 *
 * Тело — JSON: events (что изменилось), urls (страницы, которые нужно
 * пересобрать) и tags (метки для пересборки по тегу). Подпись — заголовки
 * X-Rest-Timestamp и X-Rest-Signature: sha256=HMAC(секрет, «время.тело»).
 *
 * Отправка — после ответа админке (фоновая задача, а в консоли — на выходе из
 * скрипта), когда кеш API уже сброшен: иначе фронт мог бы получить старое и
 * закешировать его заново. Несколько правок за один запрос — одно уведомление.
 *
 * Только инфоблоки из белого списка API и highload-блоки из hlblocks. При
 * изменении справочника из hlblocks кеш API сбрасывается и без настройки
 * revalidate: подписи значений фильтра берутся из него.
 */
class Notifier
{
    /** @var array<string, array> снимок адресов до правки: старые адреса тоже нужно обновить */
    private static array $before = [];

    /** @var array<string, array> события за запрос, по ключу */
    private static array $events = [];

    /** @var array<string, true> */
    private static array $urls = [];

    /** @var array<string, true> */
    private static array $tags = [];

    private static bool $scheduled = false;

    public static function register(): void
    {
        $events = EventManager::getInstance();

        $events->addEventHandler('iblock', 'OnBeforeIBlockElementUpdate', [self::class, 'onBeforeElement']);
        $events->addEventHandler('iblock', 'OnAfterIBlockElementAdd', [self::class, 'onAfterElement']);
        $events->addEventHandler('iblock', 'OnAfterIBlockElementUpdate', [self::class, 'onAfterElement']);
        $events->addEventHandler('iblock', 'OnAfterIBlockElementSetPropertyValuesEx', [self::class, 'onElementProperties']);
        $events->addEventHandler('iblock', 'OnBeforeIBlockElementDelete', [self::class, 'onBeforeElementDelete']);

        $events->addEventHandler('iblock', 'OnBeforeIBlockSectionUpdate', [self::class, 'onBeforeSection']);
        $events->addEventHandler('iblock', 'OnAfterIBlockSectionAdd', [self::class, 'onAfterSection']);
        $events->addEventHandler('iblock', 'OnAfterIBlockSectionUpdate', [self::class, 'onAfterSection']);
        $events->addEventHandler('iblock', 'OnBeforeIBlockSectionDelete', [self::class, 'onBeforeSectionDelete']);

        // События ORM-сущностей highload-блоков называются по имени блока.
        foreach (array_keys(Config::get('hlblocks')) as $name) {
            foreach (['OnAfterAdd', 'OnAfterUpdate', 'OnAfterDelete'] as $event) {
                $events->addEventHandler('', $name . $event, [self::class, 'onHighload']);
            }
        }
    }

    // ---- элементы

    public static function onBeforeElement(array $fields): void
    {
        if (!self::enabled()) {
            return;
        }

        $id = (int) ($fields['ID'] ?? 0);
        $iblockId = self::iblockId($fields, $id);
        if ($id > 0 && self::code($iblockId) !== null) {
            self::$before['element:' . $id] = IblockApi::affectedUrls($iblockId, $id);
        }
    }

    public static function onAfterElement(array $fields): void
    {
        if (!self::enabled()) {
            return;
        }

        $id = (int) ($fields['ID'] ?? 0);
        if ($id > 0) {
            self::elementChanged($id, self::iblockId($fields, $id), 'element.changed');
        }
    }

    public static function onElementProperties($elementId, $iblockId): void
    {
        if (!self::enabled()) {
            return;
        }

        self::elementChanged((int) $elementId, (int) $iblockId, 'element.changed');
    }

    public static function onBeforeElementDelete($id): void
    {
        if (!self::enabled()) {
            return;
        }

        $id = (int) $id;
        $iblockId = self::iblockId([], $id);
        if (self::code($iblockId) !== null) {
            // Элемента скоро не будет: адреса считаем сейчас, а событие — после удаления.
            self::elementChanged($id, $iblockId, 'element.deleted');
        }
    }

    private static function elementChanged(int $id, int $iblockId, string $type): void
    {
        $code = self::code($iblockId);
        if ($code === null || $id <= 0) {
            return;
        }

        $urls = array_merge(self::$before['element:' . $id] ?? [], IblockApi::affectedUrls($iblockId, $id));

        self::collect($type . '|' . $code . '|' . $id, [
            'type' => $type, 'iblock' => $code, 'id' => $id,
        ], $urls, ['iblock:' . $code, 'element:' . $code . ':' . $id]);
    }

    // ---- разделы

    public static function onBeforeSection(array $fields): void
    {
        if (!self::enabled()) {
            return;
        }

        $id = (int) ($fields['ID'] ?? 0);
        $iblockId = (int) ($fields['IBLOCK_ID'] ?? 0) ?: self::sectionIblockId($id);
        if ($id > 0 && self::code($iblockId) !== null) {
            self::$before['section:' . $id] = IblockApi::affectedUrls($iblockId, 0, $id);
        }
    }

    public static function onAfterSection(array $fields): void
    {
        if (!self::enabled()) {
            return;
        }

        $id = (int) ($fields['ID'] ?? 0);
        $iblockId = (int) ($fields['IBLOCK_ID'] ?? 0) ?: self::sectionIblockId($id);
        self::sectionChanged($id, $iblockId, 'section.changed');
    }

    public static function onBeforeSectionDelete($id): void
    {
        if (!self::enabled()) {
            return;
        }

        $id = (int) $id;
        self::sectionChanged($id, self::sectionIblockId($id), 'section.deleted');
    }

    private static function sectionChanged(int $id, int $iblockId, string $type): void
    {
        $code = self::code($iblockId);
        if ($code === null || $id <= 0) {
            return;
        }

        $urls = array_merge(self::$before['section:' . $id] ?? [], IblockApi::affectedUrls($iblockId, 0, $id));

        // У раздела с новым кодом меняются адреса всех потомков: перечислить их
        // нельзя, поэтому метка iblock: покрывает весь инфоблок.
        self::collect($type . '|' . $code . '|' . $id, [
            'type' => $type, 'iblock' => $code, 'id' => $id,
        ], $urls, ['iblock:' . $code, 'section:' . $code . ':' . $id]);
    }

    // ---- highload-блоки

    public static function onHighload(Event $event): void
    {
        $entity = $event->getEntity();
        $name = $entity->getName();
        if (!isset(Config::get('hlblocks')[$name])) {
            return;
        }

        // Названия значений в форме фильтра берутся из справочника и лежат в кеше API.
        Cache::clearCache(true, Config::CACHE_DIR);

        $primary = $event->getParameter('id');
        $id = (int) (is_array($primary) ? ($primary['ID'] ?? 0) : $primary);
        self::collect('hl|' . $name . '|' . $id, [
            'type' => 'hl.changed', 'hl' => $name, 'id' => $id,
        ], [], ['hl:' . $name]);
    }

    // ---- отправка

    private static function collect(string $key, array $event, array $urls, array $tags): void
    {
        if (!self::enabled()) {
            return;
        }

        self::$events[$key] = $event;
        foreach ($urls as $url) {
            self::$urls[$url] = true;
        }
        foreach ($tags as $tag) {
            self::$tags[$tag] = true;
        }

        if (self::$scheduled) {
            return;
        }
        self::$scheduled = true;

        if (PHP_SAPI === 'cli') {
            register_shutdown_function([self::class, 'flush']);
        } else {
            Application::getInstance()->addBackgroundJob([self::class, 'flush']);
        }
    }

    /**
     * Отправляет накопленное одним запросом; при неудаче повторяет раз.
     */
    public static function flush(): void
    {
        $config = Config::get('revalidate');
        $url = (string) ($config['url'] ?? '');
        if ($url === '' || empty(self::$events)) {
            return;
        }

        $body = json_encode([
            'sent_at' => date('c'),
            'events' => array_values(self::$events),
            'urls' => array_keys(self::$urls),
            'tags' => array_keys(self::$tags),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::$events = self::$urls = self::$tags = [];
        self::$scheduled = false;

        $timeout = max(1, (int) ($config['timeout'] ?? 3));
        $timestamp = (string) time();
        $secret = (string) ($config['secret'] ?? '');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $client = new HttpClient([
                'redirect' => false,
                'socketTimeout' => $timeout,
                'streamTimeout' => $timeout,
                'waitResponse' => true,
            ]);
            $client->setHeader('Content-Type', 'application/json; charset=utf-8');
            $client->setHeader('X-Rest-Timestamp', $timestamp);
            if ($secret !== '') {
                $client->setHeader('X-Rest-Signature', 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret));
            }

            $client->post($url, $body);
            $status = (int) $client->getStatus();
            if ($status >= 200 && $status < 300) {
                return;
            }

            error_log(sprintf('[custom.rest.revalidate] %s: ответ %d, попытка %d из 2', $url, $status, $attempt));
        }
    }

    // ---- помощники

    /**
     * Настроен ли адрес фронта (revalidate.url в настройках API).
     */
    private static function enabled(): bool
    {
        return (string) (Config::get('revalidate')['url'] ?? '') !== '';
    }

    /**
     * Код инфоблока, если он открыт в настройках API; иначе null.
     */
    private static function code(int $iblockId): ?string
    {
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            return null;
        }

        return IblockApi::allowedIblocks()[$iblockId] ?? null;
    }

    private static function iblockId(array $fields, int $elementId): int
    {
        $iblockId = (int) ($fields['IBLOCK_ID'] ?? 0);
        if ($iblockId > 0 || $elementId <= 0) {
            return $iblockId;
        }

        $row = \Bitrix\Iblock\ElementTable::getList(['select' => ['IBLOCK_ID'], 'filter' => ['=ID' => $elementId], 'limit' => 1])->fetch();

        return (int) ($row['IBLOCK_ID'] ?? 0);
    }

    private static function sectionIblockId(int $sectionId): int
    {
        if ($sectionId <= 0) {
            return 0;
        }

        $row = \Bitrix\Iblock\SectionTable::getList(['select' => ['IBLOCK_ID'], 'filter' => ['=ID' => $sectionId], 'limit' => 1])->fetch();

        return (int) ($row['IBLOCK_ID'] ?? 0);
    }
}
