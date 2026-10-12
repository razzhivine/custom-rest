<?php

namespace Custom\Rest\Api;

use Custom\Rest\Config;
use Custom\Rest\Support\Content;
use Bitrix\Iblock\Model\Section as SectionModel;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * REST-методы карты сайта: список адресов страниц для sitemap.xml, который
 * собирает фронтенд. Страницы — список инфоблока, его разделы и элементы
 * (по шаблонам URL инфоблока) и статические страницы из настроек.
 *
 * Правила видимости те же, что у методов инфоблоков (активность, даты,
 * скрытые разделы), плюс исключения для карты: свойство-флаг у элемента,
 * поле-флаг у раздела и коды в настройках. Исключённый раздел уходит вместе
 * с подразделами и своими элементами.
 *
 * Наследует IblockApi ради адресов, фильтров активности и кеша;
 * регистрируются только методы этого класса.
 */
class SitemapApi extends IblockApi
{
    private const DEFAULT_PAGE_SIZE = 5000;

    private const MIN_PAGE_SIZE = 1;

    private const MAX_PAGE_SIZE = 50000;

    private const STATIC_NAME = 'static';

    private const CACHE_KEY = 'sitemap';

    /**
     * Какие карты есть и сколько в них страниц: по одной на инфоблок из
     * белого списка и «static» для статических страниц из настроек.
     * Размер страницы карты — sitemap.page_size в настройках API (по
     * умолчанию 5000, не больше 50000 — лимит одного sitemap.xml).
     */
    public static function getIndex(array $params): array
    {
        return self::run(static function () use ($params): array {
            Content::checkModules();

            return self::cacheResponse(__FUNCTION__, $params, self::CACHE_KEY, static function (): array {
                $size = self::pageSize();
                $items = [];

                foreach (self::allowedIblocks() as $iblockId => $code) {
                    $plan = self::plan($code, (int) $iblockId);
                    if ($plan['total'] === 0) {
                        continue;
                    }

                    $items[] = [
                        'name' => $code,
                        'type' => 'iblock',
                        'total' => $plan['total'],
                        'pages' => (int) ceil($plan['total'] / $size),
                        'lastmod' => self::lastmod($plan),
                    ];
                }

                $static = self::staticPages();
                if (!empty($static)) {
                    $items[] = [
                        'name' => self::STATIC_NAME,
                        'type' => 'static',
                        'total' => count($static),
                        'pages' => (int) ceil(count($static) / $size),
                        'lastmod' => null,
                    ];
                }

                return ['items' => $items, 'meta' => ['total' => count($items), 'limit' => $size]];
            });
        });
    }

    /**
     * @param array $params ['name' => код инфоблока или 'static', 'page' => int, 'images' => 'Y']
     *
     * Адреса одной страницы карты: сначала страница списка инфоблока, затем
     * разделы (в порядке дерева), затем элементы (по ID). Только url и
     * lastmod; с images=Y — ещё и ссылки на картинки (images). Адреса
     * относительные, домен добавляет фронт.
     */
    public static function getPage(array $params): array
    {
        return self::run(static function () use ($params): array {
            Content::checkModules();

            $name = self::paramString($params, 'name');
            if ($name === '') {
                self::badRequest("Параметр 'name' обязателен");
            }

            return self::cacheResponse(__FUNCTION__, $params, self::CACHE_KEY, static function () use ($params, $name): array {
                $size = self::pageSize();
                $page = max(1, self::paramInt($params, 'page', 1));
                $images = self::paramFlag($params, 'images');

                if ($name === self::STATIC_NAME) {
                    return self::staticPage($page, $size);
                }

                $iblockId = array_search($name, self::allowedIblocks(), true);
                if ($iblockId === false) {
                    self::badRequest("Карта '{$name}' недоступна");
                }

                return self::iblockPage($name, (int) $iblockId, $page, $size, $images);
            });
        });
    }

    private static function pageSize(): int
    {
        $size = (int) (Config::get('sitemap')['page_size'] ?? self::DEFAULT_PAGE_SIZE);

        return max(self::MIN_PAGE_SIZE, min(self::MAX_PAGE_SIZE, $size));
    }

    /**
     * Статические страницы из настроек: 'static' => ['/', ['url' => '/about/', 'lastmod' => '2026-01-01']].
     *
     * @return array<int, array{url: string, lastmod: ?string}>
     */
    private static function staticPages(): array
    {
        $pages = [];
        foreach ((array) (Config::get('sitemap')['static'] ?? []) as $page) {
            $url = is_array($page) ? (string) ($page['url'] ?? '') : (string) $page;
            if ($url === '') {
                continue;
            }

            $pages[] = ['url' => $url, 'lastmod' => is_array($page) && !empty($page['lastmod']) ? (string) $page['lastmod'] : null];
        }

        return $pages;
    }

    private static function staticPage(int $page, int $size): array
    {
        $all = self::staticPages();
        $total = count($all);
        self::checkPage($page, $total, $size);

        $items = array_map(
            static fn (array $row): array => ['url' => $row['url'], 'lastmod' => $row['lastmod'], 'type' => 'static'],
            array_slice($all, ($page - 1) * $size, $size)
        );

        return ['items' => $items, 'meta' => self::pageMeta($total, $page, $size)];
    }

    private static function checkPage(int $page, int $total, int $size): void
    {
        if ($page > 1 && $page > (int) ceil($total / $size)) {
            self::notFound("Страницы {$page} в этой карте нет");
        }
    }

    private static function pageMeta(int $total, int $page, int $size): array
    {
        return ['total' => $total, 'page' => $page, 'limit' => $size, 'pages' => (int) ceil($total / $size)];
    }

    /**
     * Что и как включается в карту инфоблока: настройки из sitemap.iblocks
     * плюс фильтры и количества. Страница списка, разделы и элементы
     * включены, если у инфоблока есть соответствующий шаблон URL.
     *
     * @return array<string, mixed>
     */
    private static function plan(string $code, int $iblockId): array
    {
        $iblock = Content::getIblockUrlData($iblockId);
        $own = (array) (Config::get('sitemap')['iblocks'][$code] ?? []);

        $plan = [
            'code' => $code,
            'iblockId' => $iblockId,
            'iblock' => $iblock,
            'list' => ($own['list_page'] ?? true) && (string) ($iblock['LIST_PAGE_URL'] ?? '') !== '',
            'sections' => ($own['sections'] ?? true) && (string) ($iblock['SECTION_PAGE_URL'] ?? '') !== '',
            'elements' => ($own['elements'] ?? true) && (string) ($iblock['DETAIL_PAGE_URL'] ?? '') !== '',
        ];

        $ranges = self::excludedRanges($iblockId, $own);
        $plan['sectionFilter'] = self::sectionFilter($iblockId, $ranges);
        $plan['elementFilter'] = self::elementFilter($code, $iblockId, $ranges, $own);

        $plan['sectionCount'] = $plan['sections'] ? self::countSections($plan['sectionFilter']) : 0;
        $plan['elementCount'] = $plan['elements'] ? self::countElements(Content::getEntityDataClass($code), $plan['elementFilter']) : 0;
        $plan['total'] = ($plan['list'] ? 1 : 0) + $plan['sectionCount'] + $plan['elementCount'];

        return $plan;
    }

    /**
     * Поддеревья разделов, которые в карту не идут: [[LEFT_MARGIN, RIGHT_MARGIN], …].
     * Раздел исключается полем-флагом (sitemap.iblocks.<код>.exclude_section_field,
     * пользовательское поле «Да/нет») или кодом (exclude_sections).
     */
    private static function excludedRanges(int $iblockId, array $own): array
    {
        $ranges = [];

        $codes = array_values(array_filter(array_map('strval', (array) ($own['exclude_sections'] ?? []))));
        if (!empty($codes)) {
            $rows = SectionTable::getList([
                'select' => ['LEFT_MARGIN', 'RIGHT_MARGIN'],
                'filter' => ['=IBLOCK_ID' => $iblockId, '@CODE' => $codes],
            ]);
            while ($row = $rows->fetch()) {
                $ranges[] = [(int) $row['LEFT_MARGIN'], (int) $row['RIGHT_MARGIN']];
            }
        }

        $field = (string) ($own['exclude_section_field'] ?? '');
        if ($field !== '') {
            global $USER_FIELD_MANAGER;
            if (!isset($USER_FIELD_MANAGER->GetUserFields('IBLOCK_' . $iblockId . '_SECTION')[$field])) {
                throw new SystemException("Поле исключения {$field} не найдено у разделов инфоблока {$iblockId}");
            }

            $class = SectionModel::compileEntityByIblock($iblockId);
            $rows = $class::getList([
                'select' => ['LEFT_MARGIN', 'RIGHT_MARGIN'],
                'filter' => ['=IBLOCK_ID' => $iblockId, '=' . $field => 1],
            ]);
            while ($row = $rows->fetch()) {
                $ranges[] = [(int) $row['LEFT_MARGIN'], (int) $row['RIGHT_MARGIN']];
            }
        }

        return $ranges;
    }

    /**
     * Видимые разделы вне исключённых поддеревьев.
     */
    private static function sectionFilter(int $iblockId, array $ranges): array
    {
        $filter = array_merge(['=IBLOCK_ID' => $iblockId], self::sectionActivityFilter());
        foreach ($ranges as [$left, $right]) {
            $filter[] = ['LOGIC' => 'OR', ['<LEFT_MARGIN' => $left], ['>RIGHT_MARGIN' => $right]];
        }

        return $filter;
    }

    /**
     * Видимые элементы без исключённых: не из исключённого поддерева, не по
     * коду (exclude_elements) и без значения в свойстве-флаге
     * (exclude_property — любое непустое значение исключает).
     */
    private static function elementFilter(string $code, int $iblockId, array $ranges, array $own): array
    {
        $filter = array_merge(['=IBLOCK_ID' => $iblockId], self::elementActivityFilter());

        foreach ($ranges as [$left, $right]) {
            $filter[] = [
                'LOGIC' => 'OR',
                ['=IBLOCK_SECTION_ID' => null],
                ['<IBLOCK_SECTION.LEFT_MARGIN' => $left],
                ['>IBLOCK_SECTION.RIGHT_MARGIN' => $right],
            ];
        }

        $codes = array_values(array_filter(array_map('strval', (array) ($own['exclude_elements'] ?? []))));
        if (!empty($codes)) {
            $filter['!@CODE'] = $codes;
        }

        $property = strtoupper((string) ($own['exclude_property'] ?? ''));
        if ($property !== '') {
            $meta = Content::getPropertiesMeta($iblockId)[$property] ?? null;
            if ($meta === null) {
                throw new SystemException("Свойство исключения {$property} не найдено в инфоблоке {$iblockId}");
            }

            $value = $meta['PROPERTY_TYPE'] === PropertyTable::TYPE_LIST ? $meta['CODE'] . '.ITEM.ID' : $meta['CODE'] . '.VALUE';
            $set = ['=IBLOCK_ID' => $iblockId, '!=' . $value => null];
            if ($meta['PROPERTY_TYPE'] !== PropertyTable::TYPE_LIST) {
                $set[] = ['!=' . $value => ''];
            }

            $filter = array_merge($filter, self::notInSubquery(Content::getEntityDataClass($code), $set));
        }

        return $filter;
    }

    private static function countSections(array $filter): int
    {
        $row = SectionTable::getList([
            'select' => ['CNT'],
            'filter' => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(%s)', 'ID')],
        ])->fetch();

        return (int) ($row['CNT'] ?? 0);
    }

    /**
     * Самая свежая дата изменения среди страниц карты инфоблока.
     */
    private static function lastmod(array $plan): ?string
    {
        $latest = null;

        $queries = [];
        if ($plan['elements']) {
            $queries[] = [Content::getEntityDataClass($plan['code']), $plan['elementFilter']];
        }
        if ($plan['sections']) {
            $queries[] = [SectionTable::class, $plan['sectionFilter']];
        }

        foreach ($queries as [$entity, $filter]) {
            $row = $entity::getList([
                'select' => ['M'],
                'filter' => $filter,
                'runtime' => [new ExpressionField('M', 'MAX(%s)', 'TIMESTAMP_X')],
            ])->fetch();

            $date = self::isoDate($row['M'] ?? null);
            if ($date !== null && ($latest === null || $date > $latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof DateTime) {
            return $value->format('c');
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new DateTime($value, 'Y-m-d H:i:s'))->format('c');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Страница карты инфоблока: [страница списка] + разделы + элементы
     * одной непрерывной последовательностью, разрезанной на страницы по $size.
     */
    private static function iblockPage(string $code, int $iblockId, int $page, int $size, bool $images): array
    {
        $plan = self::plan($code, $iblockId);
        self::checkPage($page, $plan['total'], $size);

        $iblock = $plan['iblock'];
        $offset = ($page - 1) * $size;
        $left = $size;
        $items = [];

        $listCount = $plan['list'] ? 1 : 0;
        if ($listCount === 1 && $offset === 0) {
            $url = self::buildUrl((string) $iblock['LIST_PAGE_URL'], self::iblockUrlFields($iblock), null, false);
            if ($url !== null) {
                $items[] = ['url' => $url, 'lastmod' => self::lastmod($plan), 'type' => 'iblock'] + ($images ? ['images' => []] : []);
            }
            $left--;
        }

        $paths = $plan['sections'] || $plan['elements'] ? self::sectionPaths($iblockId) : [];

        $sectionOffset = max(0, $offset - $listCount);
        if ($plan['sections'] && $left > 0 && $sectionOffset < $plan['sectionCount']) {
            $select = array_merge(['ID', 'CODE', 'XML_ID', 'TIMESTAMP_X'], $images ? ['PICTURE', 'DETAIL_PICTURE'] : []);
            $rows = SectionTable::getList([
                'select' => $select,
                'filter' => $plan['sectionFilter'],
                'order' => ['LEFT_MARGIN' => 'ASC'],
                'limit' => $left,
                'offset' => $sectionOffset,
            ])->fetchAll();

            self::warmImages($rows, ['PICTURE', 'DETAIL_PICTURE'], $images);
            foreach ($rows as $row) {
                $url = self::sectionUrl($iblock, $row, $paths[(int) $row['ID']] ?? self::appendCodePath('', (string) $row['CODE']));
                if ($url !== null) {
                    $items[] = self::item($url, $row, 'section', $images, ['PICTURE', 'DETAIL_PICTURE']);
                }
            }
            $left -= count($rows);
        }

        $elementOffset = max(0, $offset - $listCount - $plan['sectionCount']);
        if ($plan['elements'] && $left > 0 && $elementOffset < $plan['elementCount']) {
            $select = array_merge(['ID', 'CODE', 'XML_ID', 'IBLOCK_SECTION_ID', 'TIMESTAMP_X'], $images ? ['PREVIEW_PICTURE', 'DETAIL_PICTURE'] : []);
            $rows = Content::getEntityDataClass($code)::getList([
                'select' => $select,
                'filter' => $plan['elementFilter'],
                'order' => ['ID' => 'ASC'],
                'limit' => $left,
                'offset' => $elementOffset,
            ])->fetchAll();

            self::warmImages($rows, ['PREVIEW_PICTURE', 'DETAIL_PICTURE'], $images);
            foreach ($rows as $row) {
                $url = self::elementUrl($iblock, $row, $paths[(int) $row['IBLOCK_SECTION_ID']] ?? '');
                if ($url !== null) {
                    $items[] = self::item($url, $row, 'element', $images, ['PREVIEW_PICTURE', 'DETAIL_PICTURE']);
                }
            }
        }

        return ['items' => $items, 'meta' => self::pageMeta($plan['total'], $page, $size)];
    }

    private static function item(string $url, array $row, string $type, bool $images, array $pictureFields): array
    {
        $item = ['url' => $url, 'lastmod' => self::isoDate($row['TIMESTAMP_X'] ?? null), 'type' => $type];

        if ($images) {
            $links = [];
            foreach ($pictureFields as $field) {
                $file = Content::getFileData((int) ($row[$field] ?? 0));
                if ($file !== null) {
                    $links[] = $file['full_path'];
                }
            }
            $item['images'] = array_values(array_unique($links));
        }

        return $item;
    }

    private static function warmImages(array $rows, array $fields, bool $enabled): void
    {
        if (!$enabled || empty($rows)) {
            return;
        }

        $ids = [];
        foreach ($fields as $field) {
            array_push($ids, ...array_column($rows, $field));
        }
        Content::warmFilesCache($ids);
    }

    /**
     * Пути кодов всех видимых разделов инфоблока одним запросом:
     * [ID раздела => 'moscow/moscow-center']. Родитель в порядке дерева
     * всегда идёт раньше детей.
     *
     * @return array<int, string>
     */
    private static function sectionPaths(int $iblockId): array
    {
        $rows = SectionTable::getList([
            'select' => ['ID', 'CODE', 'IBLOCK_SECTION_ID'],
            'filter' => array_merge(['=IBLOCK_ID' => $iblockId], self::sectionActivityFilter()),
            'order' => ['LEFT_MARGIN' => 'ASC'],
        ]);

        $paths = [];
        while ($row = $rows->fetch()) {
            $parent = (int) $row['IBLOCK_SECTION_ID'];
            $paths[(int) $row['ID']] = self::appendCodePath($paths[$parent] ?? '', (string) $row['CODE']);
        }

        return $paths;
    }
}
