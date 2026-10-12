<?php

namespace Custom\Rest\Support;

use Custom\Rest\Config;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Iblock\InheritedProperty\ElementValues;
use Bitrix\Iblock\InheritedProperty\IblockValues;
use Bitrix\Iblock\InheritedProperty\SectionValues;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\SystemException;
use Bitrix\Iblock\IblockTable;

class Content
{
    private static array $entityClassCache = [];

    private static array $iblockIdCache = [];

    private static array $elementSeoValuesCache = [];

    private static array $sectionSeoValuesCache = [];

    private static array $rawFileCache = [];

    private static ?string $baseUrl = null;

    private static array $iblockUrlDataCache = [];

    private static array $propertiesMetaCache = [];

    private static array $filterPropertiesCache = [];

    public static function checkModules(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new SystemException('Модуль "iblock" не установлен');
        }
    }

    private static function getIblockRow(string $iblockCode): object
    {
        $iblock = IblockTable::getList([
            'select' => ['ID', 'API_CODE'],
            'filter' => ['=API_CODE' => $iblockCode],
            'limit' => 1,
        ])->fetchObject();

        if (!$iblock) {
            throw new SystemException("Инфоблок с символьным кодом '{$iblockCode}' не найден или у него не заполнен API_CODE.");
        }

        return $iblock;
    }

    public static function getIblockIdByCode(string $iblockCode): int
    {
        self::checkModules();

        if (isset(self::$iblockIdCache[$iblockCode])) {
            return self::$iblockIdCache[$iblockCode];
        }

        $iblock = self::getIblockRow($iblockCode);
        $iblockId = (int) $iblock->getId();
        self::$iblockIdCache[$iblockCode] = $iblockId;

        return $iblockId;
    }

    /**
     * Шаблоны URL из настроек инфоблока, его название (звено хлебных крошек)
     * и поля, которые подставляются в шаблоны (#IBLOCK_CODE#, #IBLOCK_TYPE_ID#,
     * #IBLOCK_EXTERNAL_ID#).
     */
    public static function getIblockUrlData(int $iblockId): array
    {
        if (!isset(self::$iblockUrlDataCache[$iblockId])) {
            self::checkModules();

            self::$iblockUrlDataCache[$iblockId] = IblockTable::getList([
                'select' => [
                    'ID', 'NAME', 'CODE', 'IBLOCK_TYPE_ID', 'XML_ID', 'LIST_PAGE_URL', 'SECTION_PAGE_URL', 'DETAIL_PAGE_URL',
                    'DESCRIPTION', 'DESCRIPTION_TYPE', 'PICTURE',
                ],
                'filter' => ['=ID' => $iblockId],
                'limit' => 1,
            ])->fetch() ?: [];
        }

        return self::$iblockUrlDataCache[$iblockId];
    }

    public static function getEntityDataClass(string $iblockCode): string
    {
        self::checkModules();

        if (isset(self::$entityClassCache[$iblockCode])) {
            return self::$entityClassCache[$iblockCode];
        }

        $iblock = self::getIblockRow($iblockCode);
        self::$iblockIdCache[$iblockCode] = (int) $iblock->getId();

        $entity = IblockTable::compileEntity($iblock)->getDataClass();

        if (!$entity) {
            throw new SystemException("ORM сущность для инфоблока '{$iblockCode}' не найдена.");
        }

        self::$entityClassCache[$iblockCode] = $entity;

        return $entity;
    }

    private static function getElementSeoValues(int $iblockId, int $elementId): array
    {
        $cacheKey = $iblockId . ':' . $elementId;

        if (!isset(self::$elementSeoValuesCache[$cacheKey])) {
            self::$elementSeoValuesCache[$cacheKey] = (new ElementValues($iblockId, $elementId))->getValues();
        }

        return self::$elementSeoValuesCache[$cacheKey];
    }

    private static function getSectionSeoValuesRaw(int $iblockId, int $sectionId): array
    {
        $cacheKey = $iblockId . ':' . $sectionId;

        if (!isset(self::$sectionSeoValuesCache[$cacheKey])) {
            self::$sectionSeoValuesCache[$cacheKey] = (new SectionValues($iblockId, $sectionId))->getValues();
        }

        return self::$sectionSeoValuesCache[$cacheKey];
    }

    /**
     * Ставит элементы в очередь Bitrix ValuesQueue: первый же getValues()
     * загрузит SEO для всех элементов инфоблока одним запросом вместо
     * одного запроса на каждый (см. ElementValues::queryValues()).
     */
    public static function queueElementsSeo(int $iblockId, array $elementIds): void
    {
        foreach ($elementIds as $elementId) {
            ElementValues::queue($iblockId, (int) $elementId);
        }
    }

    public static function queueSectionsSeo(int $iblockId, array $sectionIds): void
    {
        foreach ($sectionIds as $sectionId) {
            SectionValues::queue($iblockId, (int) $sectionId);
        }
    }

    /**
     * Не резолвит версии файла (b_file_version), в отличие от
     * CFile::GetFileArray()/GetByID() — для картинок инфоблока не имеет
     * значения, но использовать для версионируемых файлов Диска нельзя.
     */
    public static function warmFilesCache(array $fileIds): void
    {
        $fileIds = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn (int $id): bool => $id > 0
        )));

        $fileIds = array_values(array_diff($fileIds, array_keys(self::$rawFileCache)));

        if (empty($fileIds)) {
            return;
        }

        $rsFiles = \CFile::GetList([], ['@ID' => $fileIds]);
        while ($file = $rsFiles->Fetch()) {
            $file['SRC'] = \CFile::GetFileSRC($file);
            self::$rawFileCache[(int) $file['ID']] = $file;
        }
    }

    private static function getRawFileData(int $fileId): array|false
    {
        if (!array_key_exists($fileId, self::$rawFileCache)) {
            self::$rawFileCache[$fileId] = \CFile::GetFileArray($fileId);
        }

        return self::$rawFileCache[$fileId];
    }

    /**
     * Адрес сайта для ссылок на файлы. Если в настройках API задан base_url
     * («https://site.ru»), берётся он: за прокси, снимающим HTTPS, протокол
     * в запросе был бы http. Иначе хост — из настроек сайта, а не из заголовка
     * Host запроса: тот задаёт клиент, и ссылки на файлы можно было бы подменить.
     */
    public static function getBaseUrl(): string
    {
        if (self::$baseUrl === null && Config::getString('base_url') !== '') {
            self::$baseUrl = rtrim(Config::getString('base_url'), '/');
        }

        if (self::$baseUrl === null) {
            $request = Context::getCurrent()->getRequest();

            $host = defined('SITE_SERVER_NAME') ? (string) SITE_SERVER_NAME : '';
            if ($host === '') {
                $host = (string) Option::get('main', 'server_name', '');
            }
            if ($host === '') {
                $host = (string) $request->getHttpHost();
            }

            self::$baseUrl = ($request->isHttps() ? 'https://' : 'http://') . $host;
        }

        return self::$baseUrl;
    }

    public static function getFileData(
        int $fileId,
        int $elementId = 0,
        int $iblockId = 0,
        string $elementName = '',
        string $fileType = 'file'
    ): ?array {
        if ($fileId <= 0) {
            return null;
        }

        $fileData = self::getRawFileData($fileId);
        if (!$fileData) {
            return null;
        }

        $relativePath = $fileData['SRC'];
        $absoluteUrl = self::getBaseUrl() . $relativePath;

        $result = [
            'id' => $fileId,
            'src' => $relativePath,
            'full_path' => $absoluteUrl,
            // Не все типы файлов (не-изображения) содержат WIDTH/HEIGHT.
            'width' => (int) ($fileData['WIDTH'] ?? 0),
            'height' => (int) ($fileData['HEIGHT'] ?? 0),
            'size' => (int) ($fileData['FILE_SIZE'] ?? 0),
        ];

        $fileType = strtolower($fileType);

        if (in_array($fileType, ['preview', 'detail'], true)) {
            $alt = '';
            $title = '';

            if ($elementId > 0 && $iblockId > 0 && Loader::includeModule('iblock')) {
                $seoValues = self::getElementSeoValues($iblockId, $elementId);
                $prefix = ($fileType === 'preview') ? 'ELEMENT_PREVIEW_PICTURE_FILE_' : 'ELEMENT_DETAIL_PICTURE_FILE_';

                if (!empty($seoValues[$prefix . 'ALT'])) {
                    $alt = $seoValues[$prefix . 'ALT'];
                }

                if (!empty($seoValues[$prefix . 'TITLE'])) {
                    $title = $seoValues[$prefix . 'TITLE'];
                }
            }

            if (empty($alt)) {
                $alt = !empty($fileData['DESCRIPTION']) ? $fileData['DESCRIPTION'] : $elementName;
            }

            if (empty($title)) {
                $title = $elementName;
            }

            $result['alt'] = (string) $alt;
            $result['title'] = (string) $title;
        }

        return $result;
    }

    public static function getSeoData(int $elementId, int $iblockId, string $elementName): array
    {
        if ($elementId <= 0 || $iblockId <= 0) {
            return [];
        }

        self::checkModules();

        $seoValues = self::getElementSeoValues($iblockId, $elementId);

        return [
            'title' => (string) ($seoValues['ELEMENT_META_TITLE'] ?? $elementName),
            'description' => (string) ($seoValues['ELEMENT_META_DESCRIPTION'] ?? ''),
            'keywords' => (string) ($seoValues['ELEMENT_META_KEYWORDS'] ?? ''),
            'h1' => (string) ($seoValues['ELEMENT_PAGE_TITLE'] ?? $elementName),
        ];
    }

    /**
     * SEO страницы списка инфоблока (корень каталога): шаблоны «Для разделов»
     * из вкладки SEO инфоблока, вычисленные для самого инфоблока — так их
     * использует штатный компонент для страницы без раздела. Шаблон не
     * задан — название инфоблока.
     */
    public static function getIblockSeoData(int $iblockId, string $iblockName): array
    {
        self::checkModules();

        $seoValues = (new IblockValues($iblockId))->getValues();

        return [
            'title' => (string) (($seoValues['SECTION_META_TITLE'] ?? '') !== '' ? $seoValues['SECTION_META_TITLE'] : $iblockName),
            'description' => (string) ($seoValues['SECTION_META_DESCRIPTION'] ?? ''),
            'keywords' => (string) ($seoValues['SECTION_META_KEYWORDS'] ?? ''),
            'h1' => (string) (($seoValues['SECTION_PAGE_TITLE'] ?? '') !== '' ? $seoValues['SECTION_PAGE_TITLE'] : $iblockName),
        ];
    }

    public static function getSectionSeoData(int $sectionId, int $iblockId, string $sectionName): array
    {
        if ($sectionId <= 0 || $iblockId <= 0) {
            return [];
        }

        self::checkModules();

        $seoValues = self::getSectionSeoValuesRaw($iblockId, $sectionId);

        return [
            'title' => (string) ($seoValues['SECTION_META_TITLE'] ?? $sectionName),
            'description' => (string) ($seoValues['SECTION_META_DESCRIPTION'] ?? ''),
            'keywords' => (string) ($seoValues['SECTION_META_KEYWORDS'] ?? ''),
            'h1' => (string) ($seoValues['SECTION_PAGE_TITLE'] ?? $sectionName),
            'picture_alt' => (string) ($seoValues['SECTION_PICTURE_FILE_ALT'] ?? ''),
            'picture_title' => (string) ($seoValues['SECTION_PICTURE_FILE_TITLE'] ?? ''),
            'detail_picture_alt' => (string) ($seoValues['SECTION_DETAIL_PICTURE_FILE_ALT'] ?? ''),
            'detail_picture_title' => (string) ($seoValues['SECTION_DETAIL_PICTURE_FILE_TITLE'] ?? ''),
        ];
    }

    /**
     * Активные свойства инфоблока с символьным кодом:
     * [CODE в верхнем регистре => ['ID', 'CODE', 'NAME', 'SORT', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE',
     * 'USER_TYPE_SETTINGS_LIST']].
     * CODE в строке — в исходном регистре: так называется поле свойства в ORM.
     */
    public static function getPropertiesMeta(int $iblockId): array
    {
        if (!isset(self::$propertiesMetaCache[$iblockId])) {
            self::checkModules();

            $meta = [];
            $rs = PropertyTable::getList([
                'select' => ['ID', 'CODE', 'NAME', 'SORT', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE', 'USER_TYPE_SETTINGS_LIST'],
                'filter' => ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y', '!=CODE' => null],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            ]);
            while ($property = $rs->fetch()) {
                if ((string) $property['CODE'] !== '') {
                    $meta[strtoupper($property['CODE'])] = $property;
                }
            }

            self::$propertiesMetaCache[$iblockId] = $meta;
        }

        return self::$propertiesMetaCache[$iblockId];
    }

    /**
     * Все свойства инфоблока (getPropertiesMeta) с настройками умного фильтра
     * для раздела (0 — для всего инфоблока) с учётом наследования от
     * родительских разделов: SMART_FILTER (флаг «Показывать в умном
     * фильтре»), DISPLAY_TYPE, DISPLAY_EXPANDED, FILTER_HINT.
     *
     * Настройки хранятся, только если в инфоблоке включена привязка свойств
     * к разделам (SECTION_PROPERTY); иначе SMART_FILTER = false, а тип
     * отображения — первый доступный для типа свойства, как в админке.
     * Порядок — по сортировке свойств.
     */
    public static function getFilterProperties(int $iblockId, int $sectionId = 0): array
    {
        $cacheKey = $iblockId . ':' . $sectionId;

        if (!isset(self::$filterPropertiesCache[$cacheKey])) {
            self::checkModules();

            $links = \CIBlockSectionPropertyLink::GetArray($iblockId, $sectionId);

            $result = [];
            foreach (self::getPropertiesMeta($iblockId) as $code => $property) {
                $link = $links[$property['ID']] ?? $links[(int) $property['ID']] ?? [];
                $displayType = (string) ($link['DISPLAY_TYPE'] ?? '');
                if ($displayType === '') {
                    $available = \CIBlockSectionPropertyLink::getDisplayTypes($property['PROPERTY_TYPE'], $property['USER_TYPE']);
                    $displayType = (string) (array_key_first($available) ?? '');
                }

                $result[$code] = $property + [
                    'SMART_FILTER' => ($link['SMART_FILTER'] ?? 'N') === 'Y',
                    'DISPLAY_TYPE' => $displayType,
                    'DISPLAY_EXPANDED' => ($link['DISPLAY_EXPANDED'] ?? 'N') === 'Y',
                    'FILTER_HINT' => (string) ($link['FILTER_HINT'] ?? ''),
                ];
            }

            self::$filterPropertiesCache[$cacheKey] = $result;
        }

        return self::$filterPropertiesCache[$cacheKey];
    }

    /**
     * Значения свойств сразу для нескольких элементов: один запрос на свойство,
     * а не на элемент. Читаются через скомпилированную ORM-сущность инфоблока,
     * поэтому работают и для инфоблоков 1.0, и для 2.0 (свойства в отдельных
     * таблицах b_iblock_element_prop_s/m).
     *
     * $visibleLinks (необязательно) — fn(string $type, int[] $ids): int[], где $type
     * 'E' — элементы, 'S' — разделы: возвращает те ID, которые можно показывать.
     * Остальные значения свойств-привязок отбрасываются, чтобы ID скрытого
     * не попадал в ответ. Без неё привязки отдаются как есть.
     *
     * @return array [elementId => [code => value]]; порядок кодов у элемента
     *               соответствует порядку $propertyCodes.
     */
    public static function getPropertiesData(
        string $iblockCode,
        array $elementIds,
        array $propertyCodes,
        ?callable $visibleLinks = null
    ): array {
        $elementIds = array_values(array_unique(array_filter(
            array_map('intval', $elementIds),
            static fn (int $id): bool => $id > 0
        )));
        $propertyCodes = array_values(array_unique(array_map(
            'strtoupper',
            array_filter($propertyCodes, 'is_string')
        )));

        if (empty($elementIds) || empty($propertyCodes)) {
            return [];
        }

        self::checkModules();

        $iblockId = self::getIblockIdByCode($iblockCode);
        $entity = self::getEntityDataClass($iblockCode);

        $properties = self::getPropertiesMeta($iblockId);

        $result = [];
        $fileProperties = [];
        $linkProperties = [];

        foreach ($propertyCodes as $code) {
            if (!isset($properties[$code])) {
                continue;
            }

            $property = $properties[$code];
            // В ORM поле свойства называется его CODE в исходном регистре.
            $field = $property['CODE'];
            $key = strtolower($code);
            $isMultiple = $property['MULTIPLE'] === 'Y';

            $select = ['ELEMENT_ID' => 'ID', 'VALUE' => $field . '.VALUE'];
            if ($property['PROPERTY_TYPE'] === PropertyTable::TYPE_LIST) {
                $select['ENUM_ID'] = $field . '.ITEM.ID';
                $select['ENUM_NAME'] = $field . '.ITEM.VALUE';
                $select['ENUM_XML_ID'] = $field . '.ITEM.XML_ID';
            }

            // Для множественного свойства ORM вернёт по строке на значение —
            // в порядке ID значения, то есть в порядке, заданном в админке
            // (без сортировки фото галереи приходили бы как попало).
            $rows = $entity::getList([
                'select' => $select,
                'filter' => ['@ID' => $elementIds],
                'order' => $isMultiple ? ['ID' => 'ASC', $field . '.ID' => 'ASC'] : [],
            ]);

            while ($row = $rows->fetch()) {
                $value = self::normalizePropertyValue($property, $row);
                if ($value === null || $value === '') {
                    continue;
                }

                $elementId = (int) $row['ELEMENT_ID'];
                if ($isMultiple) {
                    $result[$elementId][$key][] = $value;
                } else {
                    $result[$elementId][$key] = $value;
                }
            }

            if ($property['PROPERTY_TYPE'] === PropertyTable::TYPE_FILE) {
                $fileProperties[$key] = $isMultiple;
            }

            if ($property['PROPERTY_TYPE'] === PropertyTable::TYPE_ELEMENT) {
                $linkProperties[$key] = ['E', $isMultiple];
            } elseif ($property['PROPERTY_TYPE'] === PropertyTable::TYPE_SECTION) {
                $linkProperties[$key] = ['S', $isMultiple];
            }
        }

        if ($visibleLinks !== null && !empty($linkProperties)) {
            $result = self::filterLinkedValues($result, $linkProperties, $visibleLinks);
        }

        if (!empty($fileProperties)) {
            $result = self::resolvePropertyFiles($result, $fileProperties);
        }

        return $result;
    }

    /**
     * Убирает из значений свойств-привязок ID, которые API не показывает.
     * Свойство, у которого ничего не осталось, у элемента пропускается.
     *
     * @param array $linkProperties [code => ['E'|'S', isMultiple]]
     */
    private static function filterLinkedValues(array $result, array $linkProperties, callable $visibleLinks): array
    {
        $ids = ['E' => [], 'S' => []];
        foreach ($result as $values) {
            foreach ($linkProperties as $key => [$type, $isMultiple]) {
                if (isset($values[$key])) {
                    array_push($ids[$type], ...($isMultiple ? $values[$key] : [$values[$key]]));
                }
            }
        }

        $visible = [];
        foreach ($ids as $type => $list) {
            $visible[$type] = array_flip($visibleLinks($type, array_values(array_unique($list))));
        }

        foreach ($result as $elementId => $values) {
            foreach ($linkProperties as $key => [$type, $isMultiple]) {
                if (!isset($values[$key])) {
                    continue;
                }

                if ($isMultiple) {
                    $kept = array_values(array_filter($values[$key], static fn (int $id): bool => isset($visible[$type][$id])));
                } else {
                    $kept = isset($visible[$type][$values[$key]]) ? $values[$key] : null;
                }

                if (empty($kept)) {
                    unset($result[$elementId][$key]);
                } else {
                    $result[$elementId][$key] = $kept;
                }
            }
        }

        return $result;
    }

    /**
     * Приводит сырое значение свойства из ORM к виду для ответа API.
     * null — значения нет, свойство у элемента пропускается.
     */
    private static function normalizePropertyValue(array $property, array $row): mixed
    {
        $value = $row['VALUE'] ?? null;

        switch ($property['PROPERTY_TYPE']) {
            case PropertyTable::TYPE_LIST:
                if (empty($row['ENUM_ID'])) {
                    return null;
                }

                return [
                    'name' => (string) ($row['ENUM_NAME'] ?? ''),
                    'value' => !empty($row['ENUM_XML_ID']) ? (string) $row['ENUM_XML_ID'] : (int) $row['ENUM_ID'],
                ];

            case PropertyTable::TYPE_FILE:
            case PropertyTable::TYPE_ELEMENT:
            case PropertyTable::TYPE_SECTION:
                return (int) $value > 0 ? (int) $value : null;

            case PropertyTable::TYPE_NUMBER:
                return is_numeric($value) ? $value + 0 : null;
        }

        if ($property['USER_TYPE'] === 'HTML') {
            return self::normalizeHtmlValue($value);
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * Свойство типа HTML/текст хранится сериализованным
     * массивом ['TEXT' => ..., 'TYPE' => 'html'|'text'].
     */
    private static function normalizeHtmlValue(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            $value = is_array($unserialized) ? $unserialized : ['TEXT' => $value, 'TYPE' => 'html'];
        }

        if (!is_array($value) || (string) ($value['TEXT'] ?? '') === '') {
            return null;
        }

        return [
            'value' => (string) $value['TEXT'],
            'type' => strtolower((string) ($value['TYPE'] ?? 'html')),
        ];
    }

    /**
     * @param array $fileProperties [code => isMultiple]
     */
    private static function resolvePropertyFiles(array $result, array $fileProperties): array
    {
        $fileIds = [];
        foreach ($result as $values) {
            foreach ($fileProperties as $key => $isMultiple) {
                if (isset($values[$key])) {
                    array_push($fileIds, ...($isMultiple ? $values[$key] : [$values[$key]]));
                }
            }
        }

        self::warmFilesCache($fileIds);

        foreach ($result as $elementId => $values) {
            foreach ($fileProperties as $key => $isMultiple) {
                if (!isset($values[$key])) {
                    continue;
                }

                if ($isMultiple) {
                    $files = array_values(array_filter(array_map(
                        static fn (int $fileId): ?array => self::getFileData($fileId),
                        $values[$key]
                    )));
                } else {
                    $files = self::getFileData($values[$key]);
                }

                if (empty($files)) {
                    unset($result[$elementId][$key]);
                } else {
                    $result[$elementId][$key] = $files;
                }
            }
        }

        return $result;
    }
}
