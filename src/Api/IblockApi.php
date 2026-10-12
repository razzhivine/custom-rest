<?php

namespace Custom\Rest\Api;

use Custom\Rest\Config;
use Custom\Rest\Support\Content;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionTable;
use Throwable;

/**
 * REST-методы инфоблоков: элементы, разделы, дерево, хлебные крошки
 * и форма фильтра. Регистрация — в Registry.
 */
class IblockApi extends AbstractApi
{
    private const ALLOWED_ELEMENT_FIELDS = [
        'ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'ACTIVE',
        'SORT', 'XML_ID', 'TIMESTAMP_X', 'DATE_CREATE',
        'ACTIVE_FROM', 'ACTIVE_TO',
        'PREVIEW_TEXT', 'PREVIEW_PICTURE', 'DETAIL_TEXT', 'DETAIL_PICTURE',
    ];

    private const ALLOWED_SECTION_FIELDS = [
        'ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'ACTIVE',
        'SORT', 'XML_ID', 'TIMESTAMP_X', 'PICTURE', 'DETAIL_PICTURE', 'DESCRIPTION',
    ];

    /**
     * Числовые поля элементов и разделов: значение фильтра — число (self::filterNumber).
     */
    private const NUMERIC_FIELDS = ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'SORT'];

    /**
     * Параметры, от которых зависит ответ: только они входят в ключ кеша.
     * Иначе любой лишний параметр (&x=1, &x=2, …) создавал бы новый файл кеша.
     */
    private const CACHE_PARAMS = [
        'iblock_code', 'id', 'code', 'code_path', 'section_id', 'section_code_path', 'include_subsections',
        'element_id', 'element_code', 'select', 'filter', 'order', 'properties', 'page', 'limit', 'depth',
        'name', 'images',
    ];

    /**
     * Тип отображения из настроек умного фильтра → значение для фронта.
     */
    private const DISPLAY_TYPES = [
        'A' => 'range_slider',
        'B' => 'range',
        'F' => 'checkbox',
        'G' => 'checkbox_pictures',
        'H' => 'checkbox_pictures_names',
        'K' => 'radio',
        'P' => 'select',
        'R' => 'select_pictures_names',
        'U' => 'calendar',
    ];

    private const ELEMENT_DETAIL_SELECT = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'DATE_CREATE', 'DETAIL_PICTURE', 'DETAIL_TEXT'];

    private const ELEMENT_LIST_SELECT = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'DATE_CREATE', 'PREVIEW_PICTURE', 'PREVIEW_TEXT'];

    private const SECTION_SELECT = [
        'ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'NAME', 'CODE', 'PICTURE', 'DETAIL_PICTURE', 'DESCRIPTION', 'SORT',
    ];

    private const SECTION_TREE_SELECT = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'SORT'];

    /**
     * ORM отдаёт числа строками; в ответе API они приводятся к int.
     */
    private const INT_FIELDS = ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'SORT'];

    /**
     * Текстовое поле → поле с его типом (html/text). Если текст в выборке,
     * тип запрашивается тоже и отдаётся вместе с текстом одним объектом
     * {value, type}: фронт должен знать, выводить ли его как HTML.
     */
    private const TEXT_TYPE_FIELDS = [
        'PREVIEW_TEXT' => 'PREVIEW_TEXT_TYPE',
        'DETAIL_TEXT' => 'DETAIL_TEXT_TYPE',
        'DESCRIPTION' => 'DESCRIPTION_TYPE',
    ];

    /**
     * Поля, без которых не построить ссылку, крошки и дерево. Если клиент
     * их не запрашивал, они выбираются, но в ответ не попадают.
     */
    private const ELEMENT_SERVICE_FIELDS = ['NAME', 'CODE', 'XML_ID', 'IBLOCK_SECTION_ID'];

    private const SECTION_SERVICE_FIELDS = ['CODE', 'XML_ID', 'IBLOCK_SECTION_ID'];

    private const HOME_NAME = 'Главная';

    /**
     * Поля-даты элементов и разделов: значения фильтра переводятся
     * в объект даты (self::filterDate).
     */
    private const DATETIME_FIELDS = ['TIMESTAMP_X', 'DATE_CREATE', 'ACTIVE_FROM', 'ACTIVE_TO'];

    private const PROPERTY_PREFIX = 'PROPERTY.';

    private const TREE_DEFAULT_DEPTH = 3;

    private const TREE_MAX_DEPTH = 10;

    private const TREE_MAX_NODES = 1000;

    private const CACHE_TTL = 3600;

    protected static function paramFlag(array $params, string $key): bool
    {
        return in_array(strtoupper(self::paramString($params, $key)), ['Y', '1', 'TRUE'], true);
    }

    /**
     * Инфоблоки из настроек API (Config, ключ iblocks) по API_CODE:
     * [API_CODE => ['read_properties' => [...]]]. Всё, что не в списке,
     * недоступно — в том числе инфоблоки с заявками и прочими персональными
     * данными и служебные свойства.
     *
     * Фильтровать (PROPERTY.<CODE> в filter) и строить форму фильтра
     * (filter.get) можно по read_properties и по свойствам с флагом
     * «Показывать в умном фильтре» из админки. Без properties[] форма
     * фильтра строится по свойствам с этим флагом.
     */
    protected static function iblocks(): array
    {
        return Config::get('iblocks');
    }

    private static function readProperties(string $iblockCode): array
    {
        return array_map('strtoupper', self::iblocks()[$iblockCode]['read_properties'] ?? []);
    }

    private static function ensureFields(array $select, array $required): array
    {
        foreach ($required as $field) {
            if (!in_array($field, $select, true)) {
                $select[] = $field;
            }
        }

        return $select;
    }

    /**
     * @return array{0: string, 1: int} [API_CODE, ID] инфоблока из белого списка
     */
    protected static function resolveIblock(array $params): array
    {
        $iblockCode = self::paramString($params, 'iblock_code');
        if ($iblockCode === '') {
            self::badRequest("Параметр 'iblock_code' обязателен");
        }

        if (!isset(self::iblocks()[$iblockCode])) {
            self::badRequest("Инфоблок '{$iblockCode}' недоступен");
        }

        return [$iblockCode, Content::getIblockIdByCode($iblockCode)];
    }

    /**
     * Для других модулей API (поиск): инфоблоки из белого списка
     * [ID => API_CODE]. $codes — подмножество кодов; код не из белого
     * списка — 400, как в методах инфоблоков. Пусто — все из списка.
     */
    public static function allowedIblocks(array $codes = []): array
    {
        Content::checkModules();

        $codes = empty($codes) ? array_keys(self::iblocks()) : array_values(array_unique(array_map('strval', $codes)));

        $result = [];
        foreach ($codes as $code) {
            [, $id] = self::resolveIblock(['iblock_code' => $code]);
            $result[$id] = $code;
        }

        return $result;
    }

    /**
     * Раздел из параметров: $idKey (ID) или $pathKey — путь символьных
     * кодов от корня инфоблока, как #SECTION_CODE_PATH# в URL
     * («moscow/moscow-center»). Ни того ни другого — 0 (корень инфоблока).
     * Путь не найден или раздел скрыт — 404.
     */
    private static function resolveSectionParam(array $params, int $iblockId, string $idKey, string $pathKey): int
    {
        $path = trim(self::paramString($params, $pathKey), '/');

        if ($path === '') {
            return self::paramInt($params, $idKey);
        }

        if (!empty($params[$idKey])) {
            self::badRequest("Укажите либо '{$idKey}', либо '{$pathKey}'");
        }

        return self::findSectionByCodePath($iblockId, $path)
            ?? self::notFound("Раздел '{$path}' не найден");
    }

    /**
     * ID раздела по пути кодов одним запросом: выбираются видимые разделы
     * с кодами из пути, затем путь проходится от корня по IBLOCK_SECTION_ID.
     * Не найден или скрыт — null.
     */
    private static function findSectionByCodePath(int $iblockId, string $path): ?int
    {
        $codes = array_map('rawurldecode', array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $code): bool => $code !== ''
        )));

        if (empty($codes)) {
            return null;
        }

        $rows = SectionTable::getList([
            'select' => ['ID', 'CODE', 'IBLOCK_SECTION_ID'],
            'filter' => array_merge(
                ['=IBLOCK_ID' => $iblockId, '@CODE' => array_values(array_unique($codes))],
                self::sectionActivityFilter()
            ),
        ])->fetchAll();

        $parentId = null;
        foreach ($codes as $code) {
            $found = null;
            foreach ($rows as $row) {
                $rowParent = $row['IBLOCK_SECTION_ID'] === null ? null : (int) $row['IBLOCK_SECTION_ID'];
                if ($row['CODE'] === $code && $rowParent === $parentId) {
                    $found = (int) $row['ID'];
                    break;
                }
            }

            if ($found === null) {
                return null;
            }
            $parentId = $found;
        }

        return $parentId;
    }

    /**
     * Условие поиска одного элемента: 'id' или 'code'. К 'code' можно
     * добавить 'section_code_path' — путь основного раздела из URL: элемент
     * ищется только в этом разделе (пустой путь — в корне инфоблока).
     * Так одинаковые коды в разных разделах не путаются, а неканонический
     * URL даёт 404.
     *
     * @return array{0: array, 1: string} [фильтр, описание для ошибки 404]
     */
    private static function elementLookup(array $params, int $iblockId): array
    {
        $id = self::paramInt($params, 'id');
        $code = self::paramString($params, 'code');

        if (($id > 0) === ($code !== '')) {
            self::badRequest("Нужен ровно один из параметров 'id' или 'code'");
        }

        if ($id > 0) {
            return [['=ID' => $id], "с ID {$id}"];
        }

        $filter = ['=CODE' => $code];
        if (array_key_exists('section_code_path', $params)) {
            $path = trim(self::paramString($params, 'section_code_path'), '/');
            $filter['=IBLOCK_SECTION_ID'] = $path === ''
                ? null
                : (self::findSectionByCodePath($iblockId, $path) ?? self::notFound("Раздел '{$path}' не найден"));
        }

        return [$filter, "с кодом '{$code}'"];
    }

    /**
     * Ключи-алиасы отбрасываются: клиент передаёт только список полей.
     * К текстовым полям добавляется их тип (TEXT_TYPE_FIELDS).
     */
    private static function prepareSelect(array $select, array $allowed): array
    {
        $result = [];

        foreach ($select as $field) {
            if (!is_string($field) || !in_array(strtoupper($field), $allowed, true)) {
                self::badRequest('Выборка поля \'' . (is_string($field) ? $field : gettype($field)) . '\' не разрешена.');
            }
            $result[] = strtoupper($field);
        }

        $result = self::ensureFields(array_values(array_unique($result)), ['ID', 'IBLOCK_ID']);

        return self::ensureFields(
            $result,
            array_values(array_intersect_key(self::TEXT_TYPE_FIELDS, array_flip($result)))
        );
    }

    /**
     * Поле из filter/order клиента → поле ORM. PROPERTY.<CODE> — свойство
     * из $propertyFields (см. self::propertyFields), остальное — по белому
     * списку полей.
     */
    private static function resolveField(string $field, array $allowed, array $propertyFields, string $action): string
    {
        if (str_starts_with($field, self::PROPERTY_PREFIX)) {
            $code = substr($field, strlen(self::PROPERTY_PREFIX));
            if (!isset($propertyFields[$code])) {
                self::badRequest("{$action} по свойству '{$code}' не разрешена.");
            }

            return $propertyFields[$code];
        }

        if (!in_array($field, $allowed, true)) {
            self::badRequest("{$action} по полю '{$field}' не разрешена.");
        }

        return $field;
    }

    /**
     * Условие фильтра клиента для prepareFilter: [поле ORM, значение,
     * через подзапрос — у свойств]. Даты — в объект для ORM, числовые
     * поля и свойства — только числа.
     *
     * @param array $propertyFields self::propertyFields
     */
    private static function resolveFilterField(string $field, mixed $value, array $allowed, array $propertyFields): array
    {
        $ormField = self::resolveField($field, $allowed, $propertyFields['filter'], 'Фильтрация');
        $isProperty = str_starts_with($field, self::PROPERTY_PREFIX);

        if (in_array($field, self::DATETIME_FIELDS, true)) {
            $value = self::filterDate($value, true);
        } elseif (
            in_array($field, self::NUMERIC_FIELDS, true)
            || ($isProperty && isset($propertyFields['numeric'][substr($field, strlen(self::PROPERTY_PREFIX))]))
        ) {
            $value = self::filterNumber($value, $field);
        }

        return [$ormField, $value, $isProperty];
    }

    /**
     * Свойства, доступные для фильтра в разделе $sectionId (0 — инфоблок):
     * из read_properties и с флагом «Показывать в умном фильтре», кроме
     * файлов и HTML. Строки Content::getFilterProperties.
     */
    private static function filterableProperties(string $iblockCode, int $iblockId, int $sectionId = 0): array
    {
        $read = self::readProperties($iblockCode);

        return array_filter(
            Content::getFilterProperties($iblockId, $sectionId),
            static fn (array $property, string $code): bool => ($property['SMART_FILTER'] || in_array($code, $read, true))
                && self::isComparableProperty($property),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Поля ORM для PROPERTY.<CODE>. Фильтр — self::filterableProperties,
     * сортировка — немножественные свойства из read_properties: у
     * множественного нет одного значения для порядка. Список фильтруется
     * по XML_ID значения (то, что API отдаёт как value) и сортируется по
     * сортировке значения. negate — отрицание по свойству для
     * prepareFilter: значения свойства в отдельной таблице, поэтому
     * «!PROPERTY.<CODE>» — «элемента нет среди подходящих». numeric —
     * свойства, значение которых число (число, привязка к элементу или
     * разделу): фильтр по ним принимает только числа.
     *
     * @return array{filter: array<string, string>, order: array<string, string>,
     *               numeric: array<string, true>, negate: callable}
     */
    private static function propertyFields(string $iblockCode, int $iblockId, int $sectionId = 0): array
    {
        $result = [
            'filter' => [],
            'order' => [],
            'numeric' => [],
            'negate' => static fn (array $condition): array => self::notInSubquery(
                Content::getEntityDataClass($iblockCode),
                ['=IBLOCK_ID' => $iblockId, $condition]
            ),
        ];

        $numericTypes = [PropertyTable::TYPE_NUMBER, PropertyTable::TYPE_ELEMENT, PropertyTable::TYPE_SECTION];
        foreach (self::filterableProperties($iblockCode, $iblockId, $sectionId) as $code => $property) {
            $result['filter'][$code] = self::propertyValueField($property, 'XML_ID');
            if (in_array($property['PROPERTY_TYPE'], $numericTypes, true)) {
                $result['numeric'][$code] = true;
            }
        }

        $meta = Content::getPropertiesMeta($iblockId);
        foreach (self::readProperties($iblockCode) as $code) {
            $property = $meta[strtoupper($code)] ?? null;
            if ($property !== null && $property['MULTIPLE'] !== 'Y' && self::isComparableProperty($property)) {
                $result['order'][strtoupper($code)] = self::propertyValueField($property, 'SORT');
            }
        }

        return $result;
    }

    private static function isComparableProperty(array $property): bool
    {
        return $property['PROPERTY_TYPE'] !== PropertyTable::TYPE_FILE && $property['USER_TYPE'] !== 'HTML';
    }

    /**
     * Поле ORM со значением свойства; у списка — поле его значения ($listField).
     */
    private static function propertyValueField(array $property, string $listField): string
    {
        return $property['PROPERTY_TYPE'] === PropertyTable::TYPE_LIST
            ? $property['CODE'] . '.ITEM.' . $listField
            : $property['CODE'] . '.VALUE';
    }

    /**
     * Коды свойств из properties[] в верхнем регистре; не строка — 400.
     */
    private static function propertyCodesParam(array $params): array
    {
        return array_map(static function ($code): string {
            if (!is_string($code)) {
                self::badRequest("Параметр 'properties' — список кодов свойств.");
            }

            return strtoupper($code);
        }, array_values(self::paramArray($params, 'properties', [])));
    }

    /**
     * Коды из properties[] — только из read_properties инфоблока.
     */
    private static function prepareReadProperties(array $params, string $iblockCode): array
    {
        $allowed = self::readProperties($iblockCode);
        $codes = [];

        foreach (self::propertyCodesParam($params) as $code) {
            if (!in_array($code, $allowed, true)) {
                self::badRequest("Свойство '{$code}' недоступно.");
            }
            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    /**
     * select/filter/order для getList из параметров клиента, проверенные
     * по белому списку. Клиентский фильтр кладётся вложенной группой, а
     * обязательные условия ($forcedFilter, включая активность) — рядом на
     * верхнем уровне. Так LOGIC => OR из клиентского фильтра действует
     * только внутри его группы и не может «разомкнуть» обязательные условия.
     */
    private static function prepareQuery(
        array $params,
        array $allowed,
        array $defaultSelect,
        array $forcedFilter,
        array $defaultOrder = [],
        array $propertyFields = ['filter' => [], 'order' => [], 'numeric' => []]
    ): array {
        $usesProperties = false;
        $clientFilter = self::prepareFilter(
            self::paramArray($params, 'filter', []),
            static function (string $field, mixed $value) use ($allowed, $propertyFields, &$usesProperties): array {
                $usesProperties = $usesProperties || str_starts_with($field, self::PROPERTY_PREFIX);

                return self::resolveFilterField($field, $value, $allowed, $propertyFields);
            },
            $propertyFields['negate'] ?? null
        );

        $filter = $forcedFilter;
        if (!empty($clientFilter)) {
            $filter[] = $clientFilter;
        }

        $query = [
            'select' => self::prepareSelect(self::paramArray($params, 'select', $defaultSelect), $allowed),
            'filter' => $filter,
            'order' => self::prepareOrder(
                self::paramArray($params, 'order', $defaultOrder),
                static fn (string $field): string => self::resolveField($field, $allowed, $propertyFields['order'], 'Сортировка')
            ),
        ];

        // Условие на множественное свойство даёт по строке на каждое
        // совпавшее значение — без этого элементы задвоятся, а total соврёт.
        if ($usesProperties) {
            $query['data_doubling'] = false;
        }

        return $query;
    }

    /**
     * Активность элемента как в публичной части: флаг ACTIVE, период
     * активности (ACTIVE_FROM/TO) и активность основного раздела вместе
     * со всеми родителями (GLOBAL_ACTIVE). Иначе видны запланированные
     * публикации и элементы скрытых разделов. Элементы в корне инфоблока
     * (без раздела) видны. Клиент не может это ослабить своим фильтром.
     *
     * Проверяется только основной раздел (IBLOCK_SECTION_ID): элемент,
     * привязанный ещё и к другим разделам, скрывается вместе с основным.
     */
    protected static function elementActivityFilter(): array
    {
        $now = new DateTime();

        return [
            '=ACTIVE' => 'Y',
            ['LOGIC' => 'OR', '=ACTIVE_FROM' => null, '<=ACTIVE_FROM' => $now],
            ['LOGIC' => 'OR', '=ACTIVE_TO' => null, '>=ACTIVE_TO' => $now],
            ['LOGIC' => 'OR', '=IBLOCK_SECTION_ID' => null, '=IBLOCK_SECTION.GLOBAL_ACTIVE' => 'Y'],
        ];
    }

    /**
     * GLOBAL_ACTIVE учитывает активность всех родительских разделов.
     */
    protected static function sectionActivityFilter(): array
    {
        return ['=ACTIVE' => 'Y', '=GLOBAL_ACTIVE' => 'Y'];
    }

    /**
     * Даты ORM (Main\Type\Date/DateTime) — в ISO 8601, а не объектом,
     * чья JSON-сериализация зависит от версии ядра. Числовые поля — int.
     */
    private static function textType(string $type): string
    {
        return strtolower($type) === 'html' ? 'html' : 'text';
    }

    private static function normalizeValues(array $row): array
    {
        // Текст и его тип — одним объектом: {"value": "...", "type": "html"|"text"}.
        foreach (self::TEXT_TYPE_FIELDS as $textField => $typeField) {
            if (array_key_exists($textField, $row)) {
                $row[$textField] = [
                    'value' => (string) ($row[$textField] ?? ''),
                    'type' => self::textType((string) ($row[$typeField] ?? '')),
                ];
                unset($row[$typeField]);
            }
        }

        foreach ($row as $key => $value) {
            if (in_array($key, self::INT_FIELDS, true)) {
                $row[$key] = $value === null ? null : (int) $value;
            } elseif ($value instanceof DateTime) {
                $row[$key] = $value->format('c');
            } elseif ($value instanceof Date) {
                $row[$key] = $value->format('Y-m-d');
            }
        }

        return $row;
    }

    /**
     * Разделы от корня инфоблока до $sectionId включительно; у каждого
     * CODE_PATH — накопленный путь кодов, как #SECTION_CODE_PATH#.
     *
     * $sectionId <= 0 (корень инфоблока) — []; null — раздела нет или он
     * скрыт (сам или через родителя). Предки выбираются по вложенным
     * множествам (LEFT/RIGHT_MARGIN), без рекурсии. Раз сам раздел
     * GLOBAL_ACTIVE, все его предки тоже активны.
     */
    private static function loadSectionChain(int $iblockId, int $sectionId): ?array
    {
        if ($sectionId <= 0) {
            return [];
        }

        $section = SectionTable::getList([
            'select' => ['LEFT_MARGIN', 'RIGHT_MARGIN'],
            'filter' => array_merge(['=IBLOCK_ID' => $iblockId, '=ID' => $sectionId], self::sectionActivityFilter()),
            'limit' => 1,
        ])->fetch();

        if (!$section) {
            return null;
        }

        $rows = SectionTable::getList([
            'select' => ['ID', 'NAME', 'CODE', 'XML_ID', 'LEFT_MARGIN', 'RIGHT_MARGIN', 'DEPTH_LEVEL'],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '<=LEFT_MARGIN' => $section['LEFT_MARGIN'],
                '>=RIGHT_MARGIN' => $section['RIGHT_MARGIN'],
            ],
            'order' => ['LEFT_MARGIN' => 'ASC'],
        ]);

        $chain = [];
        $codePath = '';
        while ($row = $rows->fetch()) {
            $codePath = self::appendCodePath($codePath, (string) ($row['CODE'] ?? ''));
            $chain[] = $row + ['CODE_PATH' => $codePath];
        }

        return $chain;
    }

    protected static function appendCodePath(string $codePath, string $code): string
    {
        return ltrim($codePath . '/' . rawurlencode($code), '/');
    }

    /**
     * Хлебные крошки: «Главная» → инфоблок → разделы цепочки
     * (self::loadSectionChain) → элемент, если передан (строка с ID, NAME,
     * CODE, XML_ID, IBLOCK_SECTION_ID). Общая для breadcrumbs.get
     * и для крошек внутри остальных методов.
     *
     * Звено: {type: home|iblock|section|element, id, name, code, url}.
     * Ссылки — по шаблонам из настроек инфоблока («URL страницы списка»,
     * «URL страницы раздела», «URL детальной страницы»); шаблон не задан — null.
     */
    private static function buildBreadcrumbs(int $iblockId, array $sectionChain, ?array $element = null): array
    {
        $iblock = Content::getIblockUrlData($iblockId);

        $crumbs = [
            ['type' => 'home', 'id' => null, 'name' => self::HOME_NAME, 'code' => null, 'url' => self::siteDir()],
            [
                'type' => 'iblock',
                'id' => $iblockId,
                'name' => (string) ($iblock['NAME'] ?? ''),
                'code' => (string) ($iblock['CODE'] ?? ''),
                'url' => self::buildUrl((string) ($iblock['LIST_PAGE_URL'] ?? ''), self::iblockUrlFields($iblock), null, false),
            ],
        ];

        foreach ($sectionChain as $section) {
            $crumbs[] = [
                'type' => 'section',
                'id' => (int) $section['ID'],
                'name' => (string) $section['NAME'],
                'code' => (string) ($section['CODE'] ?? ''),
                'url' => self::sectionUrl($iblock, $section, $section['CODE_PATH']),
            ];
        }

        if ($element !== null) {
            $crumbs[] = [
                'type' => 'element',
                'id' => (int) $element['ID'],
                'name' => (string) $element['NAME'],
                'code' => (string) ($element['CODE'] ?? ''),
                'url' => self::elementUrl($iblock, $element, end($sectionChain)['CODE_PATH'] ?? ''),
            ];
        }

        return $crumbs;
    }

    protected static function siteDir(): string
    {
        return defined('SITE_DIR') ? SITE_DIR : '/';
    }

    protected static function iblockUrlFields(array $iblock): array
    {
        return [
            'IBLOCK_ID' => $iblock['ID'] ?? 0,
            'IBLOCK_CODE' => $iblock['CODE'] ?? '',
            'IBLOCK_TYPE_ID' => $iblock['IBLOCK_TYPE_ID'] ?? '',
            'IBLOCK_EXTERNAL_ID' => $iblock['XML_ID'] ?? '',
        ];
    }

    /**
     * «URL страницы раздела». $codePath — готовый #SECTION_CODE_PATH#.
     */
    protected static function sectionUrl(array $iblock, array $section, string $codePath): ?string
    {
        return self::buildUrl((string) ($iblock['SECTION_PAGE_URL'] ?? ''), [
            'ID' => $section['ID'],
            'CODE' => $section['CODE'] ?? '',
            'EXTERNAL_ID' => $section['XML_ID'] ?? '',
        ] + self::iblockUrlFields($iblock), $codePath, 'S');
    }

    /**
     * «URL детальной страницы». $codePath — путь кодов основного раздела,
     * если уже известен; null — Битрикс достанет его сам (с кешем по разделу).
     */
    protected static function elementUrl(array $iblock, array $element, ?string $codePath): ?string
    {
        return self::buildUrl((string) ($iblock['DETAIL_PAGE_URL'] ?? ''), [
            'ID' => $element['ID'],
            'CODE' => $element['CODE'] ?? '',
            'EXTERNAL_ID' => $element['XML_ID'] ?? '',
            'IBLOCK_SECTION_ID' => $element['IBLOCK_SECTION_ID'] ?? 0,
        ] + self::iblockUrlFields($iblock), $codePath, 'E');
    }

    /**
     * Подстановка шаблона URL инфоблока штатным CIBlock::Replace*Url.
     * #SITE_DIR# и (если известен) #SECTION_CODE_PATH# подставляются
     * заранее: путь кодов уже есть из цепочки разделов, а Битрикс ради
     * него сделал бы отдельный запрос.
     *
     * @param 'E'|'S'|false $type
     */
    protected static function buildUrl(string $template, array $fields, ?string $codePath, string|false $type): ?string
    {
        if ($template === '') {
            return null;
        }

        $search = ['#SITE_DIR#'];
        $replace = [self::siteDir()];
        if ($codePath !== null) {
            $search[] = '#SECTION_CODE_PATH#';
            $replace[] = $codePath;
        }
        $template = str_replace($search, $replace, $template);

        return $type === 'S'
            ? \CIBlock::ReplaceSectionUrl($template, $fields, false, 'S')
            : \CIBlock::ReplaceDetailUrl($template, $fields, false, $type);
    }

    /**
     * Добавляет в select поля, нужные для ссылок и крошек. Возвращает те,
     * что клиент не запрашивал: их убирают из ответа (self::removeServiceFields).
     */
    private static function addServiceFields(array &$select, array $fields): array
    {
        $added = array_values(array_diff($fields, $select));
        $select = array_merge($select, $added);

        return $added;
    }

    private static function removeServiceFields(array $item, array $fields): array
    {
        foreach ($fields as $field) {
            unset($item[strtolower($field)]);
        }

        return $item;
    }

    /**
     * run() с подключением модуля iblock и тегированным кешем ответа. Ключ —
     * метод и параметры, от которых зависит ответ (CACHE_PARAMS), плюс
     * протокол: от него зависят абсолютные ссылки на файлы, — и время
     * изменения файлов API и его настроек: после выкладки нового кода
     * старые ответы не отдаются. Теги
     * iblock_id_<ID> всех открытых инфоблоков сбрасывает сам модуль
     * инфоблоков при изменении элементов, разделов и свойств — как у
     * стандартных компонентов. Теги всех, а не одного: ответ зависит
     * и от привязанных элементов других инфоблоков.
     * Ошибки (404 и т. п.) не кешируются.
     *
     * Тег не ловит наступление ACTIVE_FROM/ACTIVE_TO, правку SEO-шаблонов
     * и настроек инфоблока — их задержку ограничивает CACHE_TTL.
     */
    private static function cached(string $method, array $params, callable $action): array
    {
        return self::run(static function () use ($method, $params, $action): array {
            Content::checkModules();
            [, $iblockId] = self::resolveIblock($params);

            return self::cacheResponse($method, $params, (string) $iblockId, $action);
        });
    }

    /**
     * Кеш ответа: метод, значимые параметры (CACHE_PARAMS), протокол и время
     * изменения файлов API — в ключе, теги всех открытых инфоблоков — на
     * записи. $dirKey — подпапка кеша (ID инфоблока или имя метода без
     * инфоблока). Вызывается и из наследников: их файл тоже входит в ключ.
     */
    protected static function cacheResponse(string $method, array $params, string $dirKey, callable $action): array
    {
        $files = array_merge(
            array_map(
                static fn (string $class): string => (new \ReflectionClass($class))->getFileName(),
                array_unique([self::class, static::class, AbstractApi::class, Content::class, Config::class])
            ),
            Config::files()
        );

        $cache = Cache::createInstance();
        $cacheId = md5(serialize([
            $method,
            array_intersect_key($params, array_flip(self::CACHE_PARAMS)),
            Context::getCurrent()->getRequest()->isHttps(),
            array_map(static fn (string $file): string => filemtime($file) . ':' . filesize($file), $files),
        ]));
        $cacheDir = Config::CACHE_DIR . '/' . $dirKey;

        // В кеше лежит не только ответ, но и его ETag и время построения:
        // по ним клиент проверяет, изменился ли ответ (self::sendValidators).
        if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir)) {
            $stored = $cache->getVars();
            self::sendValidators($stored['etag'], $stored['built']);

            return $stored['result'];
        }

        $cache->startDataCache();
        try {
            $result = $action();
        } catch (Throwable $e) {
            $cache->abortDataCache();
            throw $e;
        }

        $taggedCache = Application::getInstance()->getTaggedCache();
        $taggedCache->startTagCache($cacheDir);
        // Ответ зависит и от других открытых инфоблоков: названия и видимость
        // привязанных элементов и разделов, — поэтому сбрасывается любым из них.
        foreach (array_keys(self::allowedIblocks()) as $allowedId) {
            $taggedCache->registerTag('iblock_id_' . $allowedId);
        }
        $taggedCache->endTagCache();

        $stored = [
            'result' => $result,
            'etag' => md5((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)),
            'built' => time(),
        ];
        $cache->endDataCache($stored);

        self::sendValidators($stored['etag'], $stored['built']);

        return $result;
    }

    /**
     * ETag, Last-Modified и Cache-Control ответа; если клиент прислал
     * If-None-Match / If-Modified-Since и ответ не изменился — отвечает
     * 304 без тела и завершает запрос.
     *
     * ETag — хеш самого ответа (result): тот же ответ даёт тот же ETag, даже
     * если кеш пересобрался. Last-Modified — время построения закешированного
     * ответа: правка контента сбрасывает кеш и сдвигает его вперёд. 304 — только
     * на прямой GET: внутри batch и при POST заголовки условий не действуют.
     */
    private static function sendValidators(string $etag, int $built): void
    {
        if (headers_sent()) {
            return;
        }

        $etagHeader = 'W/"' . $etag . '"';
        header('ETag: ' . $etagHeader);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $built) . ' GMT');
        header('Cache-Control: private, no-cache');

        $request = Context::getCurrent()->getRequest();
        if ($request->getRequestMethod() !== 'GET' || !self::isDirectCall($request->getRequestedPage())) {
            return;
        }

        $ifNoneMatch = trim((string) $request->getHeader('If-None-Match'));
        if ($ifNoneMatch !== '') {
            $candidates = array_map(
                static fn (string $tag): string => trim(preg_replace('#^W/#', '', trim($tag)), '"'),
                explode(',', $ifNoneMatch)
            );
            $notModified = $ifNoneMatch === '*' || in_array($etag, $candidates, true);
        } else {
            $since = strtotime((string) $request->getHeader('If-Modified-Since'));
            $notModified = $since !== false && $since >= $built;
        }

        if ($notModified) {
            \CHTTP::SetStatus('304 Not Modified');
            Application::getInstance()->terminate();
        }
    }

    /**
     * Запрос адресован самому методу (…/<префикс>.element.get.json), а не пакету
     * (…/batch.json), где ответы нескольких методов отдаются одним запросом.
     */
    private static function isDirectCall(string $requestedPage): bool
    {
        return str_starts_with(basename($requestedPage), Config::prefix() . '.');
    }

    /**
     * Область выборки элементов для списков и фильтра: раздел из
     * 'section_id' / 'section_code_path' (с 'include_subsections' = Y —
     * вместе с подразделами) и активность. Скрытый раздел — 404.
     *
     * @return array{filter: array, chain: array, sectionId: int, codePath: ?string}
     *         codePath — путь кодов, общий для всех элементов области (только
     *         для одного раздела без подразделов); иначе null.
     */
    private static function elementScope(array $params, int $iblockId): array
    {
        $filter = ['=IBLOCK_ID' => $iblockId];
        $chain = [];
        $codePath = null;

        $sectionId = self::resolveSectionParam($params, $iblockId, 'section_id', 'section_code_path');
        if ($sectionId > 0) {
            $chain = self::loadSectionChain($iblockId, $sectionId)
                ?? self::notFound("Раздел с ID {$sectionId} не найден");
            $section = end($chain);

            if (self::paramFlag($params, 'include_subsections')) {
                $filter['>=IBLOCK_SECTION.LEFT_MARGIN'] = $section['LEFT_MARGIN'];
                $filter['<=IBLOCK_SECTION.RIGHT_MARGIN'] = $section['RIGHT_MARGIN'];
            } else {
                $filter['=IBLOCK_SECTION_ID'] = $sectionId;
                $codePath = $section['CODE_PATH'];
            }
        }

        return [
            'filter' => array_merge($filter, self::elementActivityFilter()),
            'chain' => $chain,
            'sectionId' => $sectionId,
            'codePath' => $codePath,
        ];
    }

    /**
     * Адреса страниц, которые меняются вместе с элементом или разделом:
     * его собственная страница, страницы всех его разделов вверх по цепочке
     * (их списки содержат его) и страница списка инфоблока. Для Custom\Rest\Revalidate\Notifier:
     * фронту нужно знать, какие страницы пересобрать. Работает и для скрытого:
     * страница, которая пропала, тоже должна обновиться. Нет шаблона URL —
     * этого вида адресов нет.
     *
     * @return string[]
     */
    public static function affectedUrls(int $iblockId, int $elementId = 0, int $sectionId = 0): array
    {
        Content::checkModules();

        $iblock = Content::getIblockUrlData($iblockId);
        $element = null;

        if ($elementId > 0) {
            $element = ElementTable::getList([
                'select' => ['ID', 'CODE', 'XML_ID', 'IBLOCK_SECTION_ID'],
                'filter' => ['=ID' => $elementId, '=IBLOCK_ID' => $iblockId],
                'limit' => 1,
            ])->fetch() ?: null;
            $sectionId = (int) ($element['IBLOCK_SECTION_ID'] ?? 0);
        }

        $chain = [];
        if ($sectionId > 0) {
            $section = SectionTable::getList([
                'select' => ['LEFT_MARGIN', 'RIGHT_MARGIN'],
                'filter' => ['=ID' => $sectionId, '=IBLOCK_ID' => $iblockId],
                'limit' => 1,
            ])->fetch();

            if ($section) {
                // Цепочка без проверки активности: скрытая страница тоже обновляется.
                $rows = SectionTable::getList([
                    'select' => ['ID', 'CODE', 'XML_ID', 'LEFT_MARGIN'],
                    'filter' => [
                        '=IBLOCK_ID' => $iblockId,
                        '<=LEFT_MARGIN' => $section['LEFT_MARGIN'],
                        '>=RIGHT_MARGIN' => $section['RIGHT_MARGIN'],
                    ],
                    'order' => ['LEFT_MARGIN' => 'ASC'],
                ]);

                $codePath = '';
                while ($row = $rows->fetch()) {
                    $codePath = self::appendCodePath($codePath, (string) ($row['CODE'] ?? ''));
                    $chain[] = self::sectionUrl($iblock, $row, $codePath);
                }
                $chain = array_reverse($chain);
                $lastPath = $codePath;
            }
        }

        $urls = [];
        if ($element !== null) {
            $urls[] = self::elementUrl($iblock, $element, $lastPath ?? '');
        }
        array_push($urls, ...$chain);
        $urls[] = self::buildUrl((string) ($iblock['LIST_PAGE_URL'] ?? ''), self::iblockUrlFields($iblock), null, false);

        return array_values(array_unique(array_filter($urls, static fn (?string $url): bool => $url !== null && $url !== '')));
    }

    /**
     * @param array $params ['iblock_code' => string]
     *
     * Сам инфоблок как страница списка (корень каталога): название, описание,
     * картинка, SEO по шаблонам «Для разделов» вкладки SEO инфоблока, адрес
     * страницы списка и хлебные крошки. Для страницы без раздела — там, где
     * у раздела был бы section.get.
     */
    public static function getIblock(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [$iblockCode, $iblockId] = self::resolveIblock($params);

            $iblock = Content::getIblockUrlData($iblockId);
            $name = (string) ($iblock['NAME'] ?? '');

            $picture = Content::getFileData((int) ($iblock['PICTURE'] ?? 0));
            if ($picture !== null) {
                $picture['alt'] = $name;
                $picture['title'] = $name;
            }

            $breadcrumbs = self::buildBreadcrumbs($iblockId, []);

            return [
                'id' => $iblockId,
                'api_code' => $iblockCode,
                'code' => (string) ($iblock['CODE'] ?? ''),
                'name' => $name,
                'description' => [
                    'value' => (string) ($iblock['DESCRIPTION'] ?? ''),
                    'type' => self::textType((string) ($iblock['DESCRIPTION_TYPE'] ?? '')),
                ],
                'picture' => $picture,
                'seo' => Content::getIblockSeoData($iblockId, $name),
                'url' => end($breadcrumbs)['url'],
                'breadcrumbs' => $breadcrumbs,
            ];
        });
    }

    /**
     * @param array $params ['iblock_code' => string, 'id' => int | 'code' => string,
     *                      'section_code_path' => string, 'select', 'filter']
     */
    public static function getElement(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [$iblockCode, $iblockId] = self::resolveIblock($params);
            [$lookup, $description] = self::elementLookup($params, $iblockId);

            $query = self::prepareQuery(
                $params,
                self::ALLOWED_ELEMENT_FIELDS,
                self::ELEMENT_DETAIL_SELECT,
                array_merge(['=IBLOCK_ID' => $iblockId], $lookup, self::elementActivityFilter()),
                [],
                self::propertyFields($iblockCode, $iblockId)
            );
            $serviceFields = self::addServiceFields($query['select'], self::ELEMENT_SERVICE_FIELDS);

            $entity = Content::getEntityDataClass($iblockCode);
            // Код может повторяться в разных разделах — без пути берётся первый.
            $element = $entity::getList(['order' => ['SORT' => 'ASC', 'ID' => 'ASC'], 'limit' => 1] + $query)->fetch();

            if (!$element) {
                self::notFound("Элемент {$description} не найден");
            }

            $elementId = (int) $element['ID'];

            // Раздел элемента уже проверен фильтром активности, цепочка есть.
            $chain = self::loadSectionChain($iblockId, (int) $element['IBLOCK_SECTION_ID']) ?? [];
            $breadcrumbs = self::buildBreadcrumbs($iblockId, $chain, $element);

            $properties = null;
            $propertyCodes = self::prepareReadProperties($params, $iblockCode);
            if (!empty($propertyCodes)) {
                $properties = Content::getPropertiesData($iblockCode, [$elementId], $propertyCodes, self::visibleLinkedIds(...))[$elementId] ?? [];
            }

            $item = self::formatElementData($element, 'detail', $properties) + [
                'url' => end($breadcrumbs)['url'],
                'breadcrumbs' => $breadcrumbs,
            ];

            return self::removeServiceFields($item, $serviceFields);
        });
    }

    /**
     * @param array $params ['iblock_code' => string, 'section_id' => int | 'section_code_path' => string,
     *                      'include_subsections' => 'Y', 'select', 'filter', 'order', 'properties',
     *                      'page', 'limit']
     *
     * Раздел — элементы, у которых он основной; с include_subsections=Y —
     * и элементы всех его видимых подразделов. Крошки — в meta.breadcrumbs:
     * до раздела или, без него, до инфоблока. Скрытый раздел — 404.
     */
    public static function getList(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [$iblockCode, $iblockId] = self::resolveIblock($params);
            [$page, $limit, $offset] = self::preparePagination($params, 10, 50);

            $scope = self::elementScope($params, $iblockId);
            $propertyCodes = self::prepareReadProperties($params, $iblockCode);

            $query = self::prepareQuery(
                $params,
                self::ALLOWED_ELEMENT_FIELDS,
                self::ELEMENT_LIST_SELECT,
                $scope['filter'],
                ['ID' => 'DESC'],
                self::propertyFields($iblockCode, $iblockId, $scope['sectionId'])
            );
            $serviceFields = self::addServiceFields($query['select'], self::ELEMENT_SERVICE_FIELDS);

            $entity = Content::getEntityDataClass($iblockCode);
            $result = $entity::getList($query + [
                'limit' => $limit,
                'offset' => $offset,
                'count_total' => true,
            ]);

            $totalCount = $result->getCount();
            $elements = $result->fetchAll();

            if (!empty($elements)) {
                Content::queueElementsSeo($iblockId, array_column($elements, 'ID'));
                Content::warmFilesCache([
                    ...array_column($elements, 'PREVIEW_PICTURE'),
                    ...array_column($elements, 'DETAIL_PICTURE'),
                ]);
            }

            $properties = !empty($propertyCodes)
                ? Content::getPropertiesData($iblockCode, array_column($elements, 'ID'), $propertyCodes, self::visibleLinkedIds(...))
                : null;

            $iblock = Content::getIblockUrlData($iblockId);

            $items = [];
            foreach ($elements as $element) {
                $item = self::formatElementData(
                    $element,
                    'preview',
                    $properties === null ? null : ($properties[(int) $element['ID']] ?? [])
                ) + ['url' => self::elementUrl($iblock, $element, $scope['codePath'])];

                $items[] = self::removeServiceFields($item, $serviceFields);
            }

            return [
                'items' => $items,
                'meta' => self::paginationMeta($totalCount, $page, $limit)
                    + ['breadcrumbs' => self::buildBreadcrumbs($iblockId, $scope['chain'])],
            ];
        });
    }

    /**
     * @param array $params ['iblock_code' => string] и ровно один из 'id', 'code_path'
     *                      (путь кодов от корня), 'code'; 'select', 'filter'
     *
     * 'code' может повторяться в разных ветках — берётся первый по дереву;
     * для однозначного поиска по URL — 'code_path'.
     */
    public static function getSection(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [, $iblockId] = self::resolveIblock($params);

            $id = self::paramInt($params, 'id');
            $code = self::paramString($params, 'code');
            $codePath = trim(self::paramString($params, 'code_path'), '/');

            if (count(array_filter([$id > 0, $code !== '', $codePath !== ''])) !== 1) {
                self::badRequest("Нужен ровно один из параметров 'id', 'code' или 'code_path'");
            }

            if ($codePath !== '') {
                $id = self::findSectionByCodePath($iblockId, $codePath)
                    ?? self::notFound("Раздел '{$codePath}' не найден");
            }

            $query = self::prepareQuery(
                $params,
                self::ALLOWED_SECTION_FIELDS,
                self::SECTION_SELECT,
                array_merge(
                    ['=IBLOCK_ID' => $iblockId],
                    $id > 0 ? ['=ID' => $id] : ['=CODE' => $code],
                    self::sectionActivityFilter()
                )
            );
            $serviceFields = self::addServiceFields($query['select'], self::SECTION_SERVICE_FIELDS);

            $section = SectionTable::getList(['order' => ['LEFT_MARGIN' => 'ASC'], 'limit' => 1] + $query)->fetch();

            if (!$section) {
                self::notFound($id > 0 ? "Раздел с ID {$id} не найден" : "Раздел с кодом '{$code}' не найден");
            }

            $chain = self::loadSectionChain($iblockId, (int) $section['ID']) ?? [];
            $iblock = Content::getIblockUrlData($iblockId);

            $item = self::formatSectionData($section, 'detail') + [
                'url' => self::sectionUrl($iblock, $section, end($chain)['CODE_PATH'] ?? ''),
                'breadcrumbs' => self::buildBreadcrumbs($iblockId, $chain),
            ];

            return self::removeServiceFields($item, $serviceFields);
        });
    }

    /**
     * @param array $params ['iblock_code' => string, 'section_id' => int | 'section_code_path' => string,
     *                      'select', 'filter', 'order', 'page', 'limit']
     *
     * Без раздела возвращаются разделы первого уровня, иначе — дочерние
     * разделы указанного (скрытый раздел — 404). Крошки до родителя —
     * в meta.breadcrumbs. Постранично: 'page', 'limit' (по умолчанию 50,
     * максимум 200).
     */
    public static function getSections(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [, $iblockId] = self::resolveIblock($params);
            $sectionId = self::resolveSectionParam($params, $iblockId, 'section_id', 'section_code_path');
            [$page, $limit, $offset] = self::preparePagination($params, 50, 200);

            $chain = self::loadSectionChain($iblockId, $sectionId)
                ?? self::notFound("Раздел с ID {$sectionId} не найден");
            // У всех разделов списка один родитель: их путь — его путь + свой код.
            $parentCodePath = end($chain)['CODE_PATH'] ?? '';

            $query = self::prepareQuery(
                $params,
                self::ALLOWED_SECTION_FIELDS,
                self::SECTION_SELECT,
                array_merge(
                    [
                        '=IBLOCK_ID' => $iblockId,
                        '=IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : null,
                    ],
                    self::sectionActivityFilter()
                ),
                ['SORT' => 'ASC', 'NAME' => 'ASC']
            );
            $serviceFields = self::addServiceFields($query['select'], self::SECTION_SERVICE_FIELDS);

            $result = SectionTable::getList($query + [
                'limit' => $limit,
                'offset' => $offset,
                'count_total' => true,
            ]);

            $totalCount = $result->getCount();
            $sections = $result->fetchAll();

            self::prepareSectionsBatch($iblockId, $sections);
            $iblock = Content::getIblockUrlData($iblockId);

            $items = [];
            foreach ($sections as $section) {
                $codePath = self::appendCodePath($parentCodePath, (string) ($section['CODE'] ?? ''));
                $item = self::formatSectionData($section, 'preview')
                    + ['url' => self::sectionUrl($iblock, $section, $codePath)];

                $items[] = self::removeServiceFields($item, $serviceFields);
            }

            return [
                'items' => $items,
                'meta' => self::paginationMeta($totalCount, $page, $limit)
                    + ['breadcrumbs' => self::buildBreadcrumbs($iblockId, $chain)],
            ];
        });
    }

    /**
     * @param array $params ['iblock_code' => string, 'section_id' => int | 'section_code_path' => string,
     *                      'depth' => int, 'select', 'filter']
     *
     * Дерево видимых разделов под указанным (без него — от корня инфоблока)
     * на 'depth' уровней вниз (по умолчанию 3, максимум 10): items — разделы
     * первого уровня, у каждого children. Одним запросом по LEFT/RIGHT_MARGIN;
     * порядок — как в дереве инфоблока (SORT, затем NAME), 'order' не
     * принимается. Не больше TREE_MAX_NODES разделов. Крошки до корня
     * дерева — в meta.breadcrumbs.
     */
    public static function getSectionTree(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [, $iblockId] = self::resolveIblock($params);
            $sectionId = self::resolveSectionParam($params, $iblockId, 'section_id', 'section_code_path');

            $depth = self::paramInt($params, 'depth', self::TREE_DEFAULT_DEPTH);
            if ($depth < 1 || $depth > self::TREE_MAX_DEPTH) {
                self::badRequest('depth — от 1 до ' . self::TREE_MAX_DEPTH . '.');
            }

            if (isset($params['order'])) {
                self::badRequest("Дерево отдаётся в порядке инфоблока, 'order' не поддерживается.");
            }

            $chain = self::loadSectionChain($iblockId, $sectionId)
                ?? self::notFound("Раздел с ID {$sectionId} не найден");
            $root = end($chain) ?: null;

            $forcedFilter = [
                '=IBLOCK_ID' => $iblockId,
                '<=DEPTH_LEVEL' => ($root ? (int) $root['DEPTH_LEVEL'] : 0) + $depth,
            ];
            if ($root) {
                $forcedFilter['>LEFT_MARGIN'] = $root['LEFT_MARGIN'];
                $forcedFilter['<RIGHT_MARGIN'] = $root['RIGHT_MARGIN'];
            }

            $query = self::prepareQuery(
                $params,
                self::ALLOWED_SECTION_FIELDS,
                self::SECTION_TREE_SELECT,
                array_merge($forcedFilter, self::sectionActivityFilter())
            );
            $serviceFields = self::addServiceFields($query['select'], self::SECTION_SERVICE_FIELDS);

            // В порядке LEFT_MARGIN родитель всегда идёт раньше детей.
            // На один больше лимита — чтобы знать, что дерево обрезано.
            $sections = SectionTable::getList(
                ['order' => ['LEFT_MARGIN' => 'ASC'], 'limit' => self::TREE_MAX_NODES + 1] + $query
            )->fetchAll();
            $truncated = count($sections) > self::TREE_MAX_NODES;
            $sections = array_slice($sections, 0, self::TREE_MAX_NODES);

            self::prepareSectionsBatch($iblockId, $sections);
            $iblock = Content::getIblockUrlData($iblockId);

            $rootId = $root ? (int) $root['ID'] : 0;
            $codePaths = [$rootId => $root['CODE_PATH'] ?? ''];
            $tree = [];
            $nodes = [];
            $placed = 0;

            foreach ($sections as $section) {
                $id = (int) $section['ID'];
                $parentId = (int) $section['IBLOCK_SECTION_ID'];
                $codePaths[$id] = self::appendCodePath($codePaths[$parentId] ?? '', (string) ($section['CODE'] ?? ''));

                $node = self::removeServiceFields(
                    self::formatSectionData($section, 'preview')
                        + ['url' => self::sectionUrl($iblock, $section, $codePaths[$id])],
                    $serviceFields
                ) + ['children' => []];

                // Раздел, чей родитель не попал в выборку (отсечён фильтром),
                // в дерево не входит: без родителя ему негде висеть.
                if ($parentId === $rootId) {
                    $tree[] = $node;
                    $nodes[$id] = &$tree[array_key_last($tree)];
                    $placed++;
                } elseif (isset($nodes[$parentId])) {
                    $nodes[$parentId]['children'][] = $node;
                    $nodes[$id] = &$nodes[$parentId]['children'][array_key_last($nodes[$parentId]['children'])];
                    $placed++;
                }
            }
            unset($nodes);

            return [
                'items' => $tree,
                'meta' => [
                    'total' => $placed,
                    'depth' => $depth,
                    'truncated' => $truncated,
                    'breadcrumbs' => self::buildBreadcrumbs($iblockId, $chain),
                ],
            ];
        });
    }

    /**
     * @param array $params ['iblock_code' => string] и одно из:
     *                      'section_id' | 'section_code_path' — цепочка до раздела включительно;
     *                      'element_id' | 'element_code' (+ 'section_code_path') — цепочка через
     *                      основной раздел элемента до самого элемента.
     */
    public static function getBreadcrumbs(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [$iblockCode, $iblockId] = self::resolveIblock($params);
            $element = null;

            $elementId = self::paramInt($params, 'element_id');
            $elementCode = self::paramString($params, 'element_code');

            if ($elementId > 0 || $elementCode !== '') {
                if (self::paramInt($params, 'section_id') > 0) {
                    self::badRequest("Укажите либо раздел, либо элемент");
                }

                $lookupParams = ['id' => $elementId, 'code' => $elementCode];
                if (array_key_exists('section_code_path', $params)) {
                    $lookupParams['section_code_path'] = $params['section_code_path'];
                }
                [$lookup, $description] = self::elementLookup($lookupParams, $iblockId);

                $entity = Content::getEntityDataClass($iblockCode);
                $element = $entity::getList([
                    'select' => array_merge(['ID'], self::ELEMENT_SERVICE_FIELDS),
                    'filter' => array_merge(['=IBLOCK_ID' => $iblockId], $lookup, self::elementActivityFilter()),
                    'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
                    'limit' => 1,
                ])->fetch();

                if (!$element) {
                    self::notFound("Элемент {$description} не найден");
                }

                $sectionId = (int) $element['IBLOCK_SECTION_ID'];
            } else {
                $sectionId = self::resolveSectionParam($params, $iblockId, 'section_id', 'section_code_path');
                if ($sectionId <= 0) {
                    self::badRequest("Нужен раздел ('section_id' или 'section_code_path') или элемент ('element_id' или 'element_code')");
                }
            }

            $chain = self::loadSectionChain($iblockId, $sectionId)
                ?? self::notFound("Раздел с ID {$sectionId} не найден");

            return self::buildBreadcrumbs($iblockId, $chain, $element);
        });
    }

    /**
     * @param array $params ['iblock_code' => string, 'section_id' => int | 'section_code_path' => string,
     *                      'include_subsections' => 'Y', 'properties' => [CODE, ...],
     *                      'filter' — текущий выбор, как в element.list]
     *
     * Форма фильтра: свойства, их значения у видимых элементов раздела
     * и счётчики с учётом текущего выбора. Свойства — из 'properties'
     * в указанном порядке (доступны read_properties и свойства с флагом
     * «Показывать в умном фильтре»), без него — все свойства с этим флагом
     * для раздела. Несколько значений одного свойства выбираются через
     * '@PROPERTY.<CODE>' => [...] (ИЛИ внутри свойства), диапазон числа —
     * через '>=PROPERTY.<CODE>' и '<=PROPERTY.<CODE>'.
     *
     * Счётчик значения — сколько элементов останется, если выбрать его при
     * текущем выборе в остальных свойствах. Выбор в самом свойстве не
     * учитывается: так видно, что даст каждая альтернатива. Значения без
     * совпадений остаются с count = 0, чтобы форма не прыгала. Выбором
     * в свойстве считаются условия PROPERTY.<CODE> на верхнем уровне filter;
     * условия во вложенных группах и по полям элемента влияют на все счётчики.
     *
     * meta.total — сколько элементов подходит под весь текущий выбор.
     */
    public static function getFilter(array $params): array
    {
        return self::cached(__FUNCTION__, $params, static function () use ($params): array {
            [$iblockCode, $iblockId] = self::resolveIblock($params);
            $scope = self::elementScope($params, $iblockId);
            $propertyFields = self::propertyFields($iblockCode, $iblockId, $scope['sectionId']);
            $entity = Content::getEntityDataClass($iblockCode);

            $clientFilter = self::paramArray($params, 'filter', []);
            $buildFilter = static function (array $client) use ($scope, $propertyFields): array {
                $prepared = self::prepareFilter(
                    $client,
                    static fn (string $field, mixed $value): array => self::resolveFilterField(
                        $field,
                        $value,
                        self::ALLOWED_ELEMENT_FIELDS,
                        $propertyFields
                    ),
                    $propertyFields['negate']
                );

                return empty($prepared) ? $scope['filter'] : array_merge($scope['filter'], [$prepared]);
            };
            // Заодно проверяет весь фильтр клиента: ошибка — 400 до подсчётов.
            $fullFilter = $buildFilter($clientFilter);

            $available = self::filterableProperties($iblockCode, $iblockId, $scope['sectionId']);
            $requested = array_values(array_unique(self::propertyCodesParam($params)));

            if (empty($requested)) {
                $properties = array_filter($available, static fn (array $property): bool => $property['SMART_FILTER']);
            } else {
                $properties = [];
                foreach ($requested as $code) {
                    if (!isset($available[$code]) || self::facetType($available[$code]) === null) {
                        self::badRequest("Свойство '{$code}' недоступно для фильтра.");
                    }
                    $properties[$code] = $available[$code];
                }
            }

            $items = [];
            foreach ($properties as $code => $property) {
                $type = self::facetType($property);
                if ($type === null) {
                    continue;
                }

                $otherClientFilter = array_filter(
                    $clientFilter,
                    static fn ($key): bool => is_int($key) || self::filterKeyProperty((string) $key) !== $code,
                    ARRAY_FILTER_USE_KEY
                );
                // Без выбора в других свойствах счётчики совпадают с базовыми.
                $countFilter = empty($otherClientFilter) ? null : $buildFilter($otherClientFilter);

                $item = [
                    'code' => $code,
                    'name' => (string) $property['NAME'],
                    'type' => $type,
                    'multiple' => $property['MULTIPLE'] === 'Y',
                    'display_type' => self::DISPLAY_TYPES[$property['DISPLAY_TYPE']] ?? null,
                    'expanded' => $property['DISPLAY_EXPANDED'],
                    'hint' => $property['FILTER_HINT'],
                ];

                if ($type === 'number') {
                    $field = $property['CODE'] . '.VALUE';
                    $range = self::facetRange($entity, $field, $scope['filter']);
                    if ($range['min'] === null) {
                        continue;
                    }

                    $item['range'] = $range;
                    $item['available'] = $countFilter === null ? $range : self::facetRange($entity, $field, $countFilter);
                    $item['selected'] = self::selectedRange($clientFilter, $code);
                } else {
                    $values = self::facetValues(
                        $entity,
                        $property,
                        $type,
                        $scope['filter'],
                        $countFilter,
                        self::selectedValues($clientFilter, $code)
                    );
                    if (empty($values)) {
                        continue;
                    }

                    $item['values'] = $values;
                }

                $items[] = $item;
            }

            return [
                'items' => $items,
                'meta' => [
                    'total' => self::countElements($entity, $fullFilter),
                    'breadcrumbs' => self::buildBreadcrumbs($iblockId, $scope['chain']),
                ],
            ];
        });
    }

    /**
     * Тип свойства для формы фильтра; null — такое свойство в форме
     * не поддерживается (файл, HTML, остальные пользовательские типы).
     * directory — свойство «Справочник» (highload-блок): значение — UF_XML_ID.
     */
    private static function facetType(array $property): ?string
    {
        return match ($property['PROPERTY_TYPE']) {
            PropertyTable::TYPE_LIST => 'list',
            PropertyTable::TYPE_NUMBER => 'number',
            PropertyTable::TYPE_ELEMENT => 'element',
            PropertyTable::TYPE_SECTION => 'section',
            PropertyTable::TYPE_STRING => match ($property['USER_TYPE']) {
                '', null => 'string',
                'directory' => 'directory',
                default => null,
            },
            default => null,
        };
    }

    /**
     * Код свойства, если ключ фильтра — условие PROPERTY.<CODE>; иначе null.
     */
    private static function filterKeyProperty(string $key): ?string
    {
        $field = strtoupper(substr($key, strspn($key, self::FILTER_OPERATORS)));

        return str_starts_with($field, self::PROPERTY_PREFIX) ? substr($field, strlen(self::PROPERTY_PREFIX)) : null;
    }

    /**
     * Выбранные значения свойства: условия '=' и '@' на верхнем уровне filter.
     */
    private static function selectedValues(array $filter, string $code): array
    {
        $selected = [];

        foreach ($filter as $key => $value) {
            if (is_int($key) || self::filterKeyProperty((string) $key) !== $code) {
                continue;
            }

            $operator = substr((string) $key, 0, strspn((string) $key, self::FILTER_OPERATORS));
            if (in_array($operator, ['', '=', '@'], true)) {
                foreach ((array) $value as $one) {
                    $selected[] = (string) $one;
                }
            }
        }

        return $selected;
    }

    /**
     * Выбранный диапазон числа: '>=' / '>' — от, '<=' / '<' — до.
     */
    private static function selectedRange(array $filter, string $code): array
    {
        $range = ['min' => null, 'max' => null];

        foreach ($filter as $key => $value) {
            if (is_int($key) || self::filterKeyProperty((string) $key) !== $code || !is_numeric($value)) {
                continue;
            }

            $operator = substr((string) $key, 0, strspn((string) $key, self::FILTER_OPERATORS));
            if (in_array($operator, ['>=', '>'], true)) {
                $range['min'] = $value + 0;
            } elseif (in_array($operator, ['<=', '<'], true)) {
                $range['max'] = $value + 0;
            }
        }

        return $range;
    }

    /**
     * Значения свойства у элементов области ($baseFilter) со счётчиками
     * под $countFilter (null — те же, что базовые).
     */
    private static function facetValues(
        string $entity,
        array $property,
        string $type,
        array $baseFilter,
        ?array $countFilter,
        array $selected
    ): array {
        $code = $property['CODE'];
        $fields = $type === 'list'
            ? [
                'F_KEY' => $code . '.ITEM.ID',
                'F_XML_ID' => $code . '.ITEM.XML_ID',
                'F_NAME' => $code . '.ITEM.VALUE',
                'F_SORT' => $code . '.ITEM.SORT',
            ]
            : ['F_KEY' => $code . '.VALUE'];

        $intKeys = !in_array($type, ['string', 'directory'], true);
        $base = self::facetCounts($entity, $fields, $baseFilter, $intKeys);
        $counts = $countFilter === null ? $base : self::facetCounts($entity, $fields, $countFilter, $intKeys);

        $names = match ($type) {
            'element' => self::linkedNames('E', array_keys($base)),
            'section' => self::linkedNames('S', array_keys($base)),
            default => [],
        };

        // Справочник: подписи и порядок — из его highload-блока, если он открыт в настройках API.
        $options = $type === 'directory'
            ? HlApi::directoryOptions((string) ($property['USER_TYPE_SETTINGS_LIST']['TABLE_NAME'] ?? ''), array_keys($base))
            : null;

        $values = [];
        foreach ($base as $key => $row) {
            if ($type === 'list') {
                $value = (string) $row['F_XML_ID'] !== '' ? (string) $row['F_XML_ID'] : (int) $key;
                $name = (string) $row['F_NAME'];
            } elseif ($type === 'element' || $type === 'section') {
                // Привязка к скрытому элементу или разделу в форму не выводится.
                if (!isset($names[$key])) {
                    continue;
                }
                $value = (int) $key;
                $name = $names[$key];
            } elseif ($type === 'directory' && $options !== null) {
                // Запись справочника выключена или удалена — в форму не выводится.
                if (!isset($options[(string) $key])) {
                    continue;
                }
                $value = (string) $key;
                $name = $options[$value]['name'];
                $row['F_SORT'] = $options[$value]['sort'];
            } else {
                $value = (string) $key;
                $name = (string) $key;
            }

            $values[] = [
                'value' => $value,
                'name' => $name,
                'count' => (int) ($counts[$key]['CNT'] ?? 0),
                'selected' => in_array((string) $value, $selected, true),
                'sort' => (int) ($row['F_SORT'] ?? 0),
            ];
        }

        usort($values, static fn (array $a, array $b): int => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);

        return array_map(static function (array $value): array {
            unset($value['sort']);
            return $value;
        }, $values);
    }

    /**
     * Число элементов на каждое значение: [ключ значения => строка с CNT].
     * COUNT(DISTINCT ID) — у множественного свойства элемент считается
     * один раз на значение, сколько бы строк ни дали соединения.
     * $intKeys — ключ это ID (значение списка, привязка): в инфоблоке 1.0
     * ORM отдаёт привязку десятичным числом («13.0000»).
     */
    private static function facetCounts(string $entity, array $fields, array $filter, bool $intKeys): array
    {
        $rows = $entity::getList([
            'select' => array_merge($fields, ['CNT']),
            'filter' => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'ID')],
        ]);

        $result = [];
        while ($row = $rows->fetch()) {
            if ($row['F_KEY'] !== null && $row['F_KEY'] !== '') {
                $result[$intKeys ? (string) (int) $row['F_KEY'] : (string) $row['F_KEY']] = $row;
            }
        }

        return $result;
    }

    /**
     * @return array{min: int|float|null, max: int|float|null}
     */
    private static function facetRange(string $entity, string $field, array $filter): array
    {
        $row = $entity::getList([
            'select' => ['F_MIN', 'F_MAX'],
            'filter' => $filter,
            'runtime' => [
                new ExpressionField('F_MIN', 'MIN(%s)', $field),
                new ExpressionField('F_MAX', 'MAX(%s)', $field),
            ],
        ])->fetch();

        return [
            'min' => is_numeric($row['F_MIN'] ?? null) ? $row['F_MIN'] + 0 : null,
            'max' => is_numeric($row['F_MAX'] ?? null) ? $row['F_MAX'] + 0 : null,
        ];
    }

    protected static function countElements(string $entity, array $filter): int
    {
        $row = $entity::getList([
            'select' => ['CNT'],
            'filter' => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'ID')],
        ])->fetch();

        return (int) ($row['CNT'] ?? 0);
    }

    /**
     * Названия привязанных элементов ('E') или разделов ('S'): [ID => NAME],
     * только видимые API: из инфоблоков белого списка, активные, с учётом дат
     * и активности раздела. Привязка может вести куда угодно, а имя скрытого
     * или закрытого в ответ попасть не должно.
     */
    private static function linkedNames(string $type, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $iblockIds = array_keys(self::allowedIblocks());
        if (empty($iblockIds)) {
            return [];
        }

        $names = [];
        $rows = $type === 'E'
            ? ElementTable::getList([
                'select' => ['ID', 'NAME'],
                'filter' => array_merge(['@ID' => $ids, '@IBLOCK_ID' => $iblockIds], self::elementActivityFilter()),
            ])
            : SectionTable::getList([
                'select' => ['ID', 'NAME'],
                'filter' => array_merge(['@ID' => $ids, '@IBLOCK_ID' => $iblockIds], self::sectionActivityFilter()),
            ]);
        while ($row = $rows->fetch()) {
            $names[(string) $row['ID']] = (string) $row['NAME'];
        }

        return $names;
    }

    /**
     * ID привязанных элементов ('E') или разделов ('S'), которые API показывает
     * (см. linkedNames). Для Content::getPropertiesData: значения свойств-привязок
     * не должны выдавать ID скрытого.
     *
     * @return int[]
     */
    private static function visibleLinkedIds(string $type, array $ids): array
    {
        return array_map('intval', array_keys(self::linkedNames($type, $ids)));
    }

    /**
     * SEO разделов одним запросом и файлы их картинок одним запросом.
     */
    private static function prepareSectionsBatch(int $iblockId, array $sections): void
    {
        if (empty($sections)) {
            return;
        }

        Content::queueSectionsSeo($iblockId, array_column($sections, 'ID'));
        Content::warmFilesCache([
            ...array_column($sections, 'PICTURE'),
            ...array_column($sections, 'DETAIL_PICTURE'),
        ]);
    }

    /**
     * @param string $mode 'detail' — полный блок SEO, 'preview' — только h1.
     */
    protected static function formatSectionData(array $section, string $mode = 'preview'): array
    {
        $sectionId = (int) ($section['ID'] ?? 0);
        $iblockId = (int) ($section['IBLOCK_ID'] ?? 0);
        $sectionName = (string) ($section['NAME'] ?? '');

        $seoValues = Content::getSectionSeoData($sectionId, $iblockId, $sectionName);

        if (array_key_exists('PICTURE', $section)) {
            $picture = Content::getFileData((int) ($section['PICTURE'] ?? 0));
            if ($picture !== null) {
                $picture['alt'] = $seoValues['picture_alt'] !== '' ? $seoValues['picture_alt'] : $sectionName;
                $picture['title'] = $seoValues['picture_title'] !== '' ? $seoValues['picture_title'] : $sectionName;
            }
            $section['PICTURE'] = $picture;
        }

        if (array_key_exists('DETAIL_PICTURE', $section)) {
            $detailPicture = Content::getFileData((int) ($section['DETAIL_PICTURE'] ?? 0));
            if ($detailPicture !== null) {
                $detailPicture['alt'] = $seoValues['detail_picture_alt'] !== '' ? $seoValues['detail_picture_alt'] : $sectionName;
                $detailPicture['title'] = $seoValues['detail_picture_title'] !== '' ? $seoValues['detail_picture_title'] : $sectionName;
            }
            $section['DETAIL_PICTURE'] = $detailPicture;
        }

        if ($mode === 'detail') {
            $section['SEO'] = [
                'title' => $seoValues['title'] ?? $sectionName,
                'description' => $seoValues['description'] ?? '',
                'keywords' => $seoValues['keywords'] ?? '',
                'h1' => $seoValues['h1'] ?? $sectionName,
            ];
        } else {
            $section['SEO'] = [
                'h1' => $seoValues['h1'] ?? $sectionName,
            ];
        }

        return array_change_key_case(self::normalizeValues($section), CASE_LOWER);
    }

    /**
     * @param array|null $properties Уже загруженные свойства элемента
     *                               (Content::getPropertiesData); null — не запрашивались.
     */
    protected static function formatElementData(
        array $element,
        string $mode = 'preview',
        ?array $properties = null
    ): array {
        $elementId = (int) ($element['ID'] ?? 0);
        $iblockId = (int) ($element['IBLOCK_ID'] ?? 0);
        $elementName = (string) ($element['NAME'] ?? '');

        // Только поля, которые были в выборке, — в любом режиме.
        foreach (['PREVIEW_PICTURE' => 'preview', 'DETAIL_PICTURE' => 'detail'] as $field => $fileType) {
            if (array_key_exists($field, $element)) {
                $element[$field] = Content::getFileData(
                    (int) ($element[$field] ?? 0),
                    $elementId,
                    $iblockId,
                    $elementName,
                    $fileType
                );
            }
        }

        $seoValues = Content::getSeoData($elementId, $iblockId, $elementName);

        if ($mode === 'detail') {
            $element['SEO'] = $seoValues;
        } else {
            $element['SEO'] = [
                'h1' => $seoValues['h1'] ?? $elementName,
            ];
        }

        if ($properties !== null) {
            // Объект, а не массив: пустые свойства — {} в JSON, а не [].
            $element['PROPERTIES'] = (object) $properties;
        }

        return array_change_key_case(self::normalizeValues($element), CASE_LOWER);
    }
}
