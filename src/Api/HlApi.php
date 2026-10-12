<?php

namespace Custom\Rest\Api;

use Custom\Rest\Config;
use Custom\Rest\Support\Content;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * REST-методы highload-блоков (модуль highloadblock): справочники — бренды,
 * удобства, города и т. п. Регистрация — в Registry.
 */
class HlApi extends AbstractApi
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    /**
     * Значения списков (enumeration) по ID поля: [ID значения => [XML_ID, VALUE]].
     */
    private static array $enumCache = [];

    /**
     * @param array $params ['hl' => NAME, 'select', 'filter', 'order', 'page', 'limit']
     *
     * Записи справочника. По умолчанию — все поля из белого списка,
     * сортировка UF_SORT (если поле есть в списке), затем ID.
     * Список (enumeration) фильтруется по XML_ID значения — то, что API
     * отдаёт в value; «да/нет» — true/false, Y/N или 1/0; даты — ISO 8601
     * или формат сайта.
     */
    public static function getList(array $params): array
    {
        return self::run(static function () use ($params): array {
            $block = self::resolveBlock($params);
            [$page, $limit, $offset] = self::preparePagination($params, self::DEFAULT_LIMIT, self::MAX_LIMIT);

            $defaultOrder = isset($block['fields']['UF_SORT']) ? ['UF_SORT' => 'ASC', 'ID' => 'ASC'] : ['ID' => 'ASC'];
            $query = self::prepareQuery($params, $block, [], $defaultOrder);

            $result = $block['class']::getList($query + [
                'limit' => $limit,
                'offset' => $offset,
                'count_total' => true,
            ]);
            $total = (int) $result->getCount();
            $rows = $result->fetchAll();

            self::warmFiles($rows, $block['fields']);

            return [
                'items' => array_map(static fn (array $row): array => self::formatRow($row, $block['fields']), $rows),
                'meta' => self::paginationMeta($total, $page, $limit),
            ];
        });
    }

    /**
     * @param array $params ['hl' => NAME, 'id' => int | 'xml_id' => string, 'select']
     *
     * Одна запись по ID или по UF_XML_ID — по нему на справочник ссылаются
     * свойства инфоблоков типа «Справочник». Не найдена или скрыта — 404.
     */
    public static function getItem(array $params): array
    {
        return self::run(static function () use ($params): array {
            $block = self::resolveBlock($params);

            $id = self::paramInt($params, 'id');
            $xmlId = self::paramString($params, 'xml_id');
            if (($id > 0) === ($xmlId !== '')) {
                self::badRequest("Нужен ровно один из параметров 'id' или 'xml_id'");
            }

            if ($xmlId !== '' && !isset($block['allFields']['UF_XML_ID'])) {
                self::badRequest("В highload-блоке '{$block['name']}' нет поля UF_XML_ID");
            }

            $lookup = $id > 0 ? ['=ID' => $id] : ['=UF_XML_ID' => $xmlId];
            $query = self::prepareQuery($params, $block, $lookup, ['ID' => 'ASC']);

            $row = $block['class']::getList(['limit' => 1] + $query)->fetch();
            if (!$row) {
                self::notFound($id > 0 ? "Запись с ID {$id} не найдена" : "Запись с xml_id '{$xmlId}' не найдена");
            }

            self::warmFiles([$row], $block['fields']);

            return self::formatRow($row, $block['fields']);
        });
    }

    /**
     * Подписи значений свойства-справочника инфоблока (тип «Справочник»):
     * [UF_XML_ID => ['name' => string, 'sort' => int]] для видимых записей.
     * Свойство хранит UF_XML_ID записи highload-блока $tableName. Подписи
     * отдаются, только если блок открыт в настройках API: запись с выключенным
     * полем активности в результат не попадает, название берётся из UF_NAME и
     * порядок из UF_SORT, если эти поля в белом списке блока. Блок не открыт —
     * null (значения остаются как есть, без подписей).
     *
     * @return array<string, array{name: string, sort: int}>|null
     */
    public static function directoryOptions(string $tableName, array $xmlIds): ?array
    {
        if ($tableName === '' || empty($xmlIds) || !Loader::includeModule('highloadblock')) {
            return null;
        }

        $hlblock = HighloadBlockTable::getList(['filter' => ['=TABLE_NAME' => $tableName], 'limit' => 1])->fetch();
        if (!$hlblock || !isset(Config::get('hlblocks')[$hlblock['NAME']])) {
            return null;
        }

        $block = self::resolveBlock(['hl' => $hlblock['NAME']]);
        if (!isset($block['allFields']['UF_XML_ID'])) {
            return null;
        }

        $nameField = isset($block['fields']['UF_NAME']) ? 'UF_NAME' : null;
        $sortField = isset($block['fields']['UF_SORT']) ? 'UF_SORT' : null;

        $filter = ['@UF_XML_ID' => array_map('strval', $xmlIds)];
        if ($block['activeField'] !== null) {
            $filter['=' . $block['activeField']] = 1;
        }

        $rows = $block['class']::getList([
            'select' => array_values(array_filter(['UF_XML_ID', $nameField, $sortField])),
            'filter' => $filter,
        ]);

        $options = [];
        while ($row = $rows->fetch()) {
            $xmlId = (string) $row['UF_XML_ID'];
            $options[$xmlId] = [
                'name' => $nameField !== null && (string) $row[$nameField] !== '' ? (string) $row[$nameField] : $xmlId,
                'sort' => $sortField !== null ? (int) $row[$sortField] : 0,
            ];
        }

        return $options;
    }

    /**
     * Блок по 'hl' из настроек API (Config, ключ hlblocks:
     * [NAME => ['fields' => [...], 'active_field' => ?string]]; всё, что не
     * в списке, недоступно): класс ORM-сущности и его поля.
     *
     * @return array{name: string, id: int, class: string, fields: array, allFields: array, activeField: ?string}
     *         fields — поля из белого списка, которые есть в блоке: [FIELD_NAME => описание поля],
     *         allFields — все пользовательские поля блока
     */
    private static function resolveBlock(array $params): array
    {
        if (!Loader::includeModule('highloadblock')) {
            throw new SystemException('Модуль highload-блоков (highloadblock) не установлен');
        }

        $name = self::paramString($params, 'hl');
        if ($name === '') {
            self::badRequest("Параметр 'hl' обязателен");
        }

        $config = Config::get('hlblocks')[$name] ?? null;
        if ($config === null) {
            self::badRequest("Highload-блок '{$name}' недоступен");
        }

        $hlblock = HighloadBlockTable::getList(['filter' => ['=NAME' => $name], 'limit' => 1])->fetch();
        if (!$hlblock) {
            throw new SystemException("Highload-блок '{$name}' из белого списка не найден");
        }

        global $USER_FIELD_MANAGER;
        $allFields = $USER_FIELD_MANAGER->GetUserFields('HLBLOCK_' . $hlblock['ID'], 0, LANGUAGE_ID);

        $fields = array_intersect_key($allFields, array_flip($config['fields'] ?? []));

        $activeField = $config['active_field'] ?? null;
        if ($activeField !== null && !isset($allFields[$activeField])) {
            throw new SystemException("Поле активности {$activeField} не найдено в highload-блоке '{$name}'");
        }

        return [
            'name' => $name,
            'id' => (int) $hlblock['ID'],
            'class' => HighloadBlockTable::compileEntity($hlblock)->getDataClass(),
            'fields' => $fields,
            'allFields' => $allFields,
            'activeField' => $activeField,
        ];
    }

    /**
     * select/filter/order для getList: только ID и поля из белого списка;
     * клиентский фильтр — вложенной группой рядом с обязательными условиями
     * ($lookup и активность), чтобы LOGIC => OR их не разомкнул.
     */
    private static function prepareQuery(array $params, array $block, array $lookup, array $defaultOrder): array
    {
        $fields = $block['fields'];

        $select = ['ID'];
        foreach (self::paramArray($params, 'select', array_keys($fields)) as $field) {
            $field = strtoupper((string) $field);
            if ($field !== 'ID' && !isset($fields[$field])) {
                self::badRequest("Выборка поля '{$field}' не разрешена.");
            }
            $select[] = $field;
        }

        $usesMultiple = false;
        $clientFilter = self::prepareFilter(
            self::paramArray($params, 'filter', []),
            static function (string $field, mixed $value) use ($fields, &$usesMultiple): array {
                if ($field === 'ID') {
                    return ['ID', self::filterNumber($value, 'ID')];
                }
                if (!isset($fields[$field])) {
                    self::badRequest("Фильтрация по полю '{$field}' не разрешена.");
                }
                $multiple = $fields[$field]['MULTIPLE'] === 'Y';
                $usesMultiple = $usesMultiple || $multiple;

                // Множественное поле — в отдельной таблице: отрицание через подзапрос.
                return [$field, self::filterValue($fields[$field], $value), $multiple];
            },
            static fn (array $condition): array => self::notInSubquery($block['class'], [$condition])
        );

        $filter = $lookup;
        if ($block['activeField'] !== null) {
            $filter['=' . $block['activeField']] = 1;
        }
        if (!empty($clientFilter)) {
            $filter[] = $clientFilter;
        }

        $order = self::prepareOrder(
            self::paramArray($params, 'order', $defaultOrder),
            static function (string $field) use ($fields): string {
                if ($field !== 'ID' && (!isset($fields[$field]) || $fields[$field]['MULTIPLE'] === 'Y')) {
                    self::badRequest("Сортировка по полю '{$field}' не разрешена.");
                }

                return $field;
            }
        );

        $query = ['select' => array_values(array_unique($select)), 'filter' => $filter, 'order' => $order];

        // Условие на множественное поле даёт строку на каждое совпавшее
        // значение — без этого записи задвоятся, а total соврёт.
        if ($usesMultiple) {
            $query['data_doubling'] = false;
        }

        return $query;
    }

    /**
     * Значение фильтра в формат хранения: XML_ID списка → ID значения,
     * true/false/Y/N → 1/0, дата в ISO или формате сайта → объект даты.
     * Массив — поэлементно.
     */
    private static function filterValue(array $field, mixed $value): mixed
    {
        // Дата — до разбора массива: список дат filterDate отклоняет целиком.
        if (in_array($field['USER_TYPE_ID'], ['date', 'datetime'], true)) {
            return self::filterDate($value, $field['USER_TYPE_ID'] === 'datetime');
        }

        if (is_array($value)) {
            return array_map(static fn ($one) => self::filterValue($field, $one), $value);
        }

        switch ($field['USER_TYPE_ID']) {
            case 'enumeration':
                foreach (self::enumValues($field) as $id => $enum) {
                    if ($enum['XML_ID'] === (string) $value) {
                        return $id;
                    }
                }

                return 0; // нет такого значения — ничего не найдётся

            case 'boolean':
                return in_array(strtoupper((string) $value), ['1', 'Y', 'TRUE'], true) ? 1 : 0;

            case 'integer':
            case 'double':
            case 'hlblock':
            case 'iblock_element':
            case 'iblock_section':
            case 'file':
                return self::filterNumber($value, $field['FIELD_NAME']);
        }

        return $value;
    }

    /**
     * Запись → ответ API: ключи в нижнем регистре, значения по типу поля.
     */
    private static function formatRow(array $row, array $fields): array
    {
        $item = ['id' => (int) $row['ID']];

        foreach ($row as $name => $value) {
            if (isset($fields[$name])) {
                $item[strtolower($name)] = $fields[$name]['MULTIPLE'] === 'Y'
                    ? array_values(array_filter(
                        array_map(static fn ($one) => self::formatValue($fields[$name], $one), (array) $value),
                        static fn ($one) => $one !== null
                    ))
                    : self::formatValue($fields[$name], $value);
            }
        }

        return $item;
    }

    /**
     * Значение поля по его типу. Список — {name, value} (value — XML_ID
     * значения, если пустой — ID), файл — объект файла, «да/нет» — bool,
     * дата — Y-m-d, дата и время — ISO 8601, привязки — ID.
     */
    private static function formatValue(array $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === false) {
            return $field['USER_TYPE_ID'] === 'boolean' ? false : null;
        }

        return match ($field['USER_TYPE_ID']) {
            'integer', 'hlblock', 'iblock_element', 'iblock_section' => (int) $value,
            'double' => (float) $value,
            'boolean' => (bool) (int) $value,
            'file' => Content::getFileData((int) $value),
            'enumeration' => self::enumOption($field, (int) $value),
            'date' => $value instanceof Date ? $value->format('Y-m-d') : (string) $value,
            'datetime' => $value instanceof DateTime ? $value->format('c') : (string) $value,
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    private static function enumOption(array $field, int $id): ?array
    {
        $enum = self::enumValues($field)[$id] ?? null;
        if ($enum === null) {
            return null;
        }

        return [
            'name' => (string) $enum['VALUE'],
            'value' => (string) $enum['XML_ID'] !== '' ? (string) $enum['XML_ID'] : $id,
        ];
    }

    private static function enumValues(array $field): array
    {
        $fieldId = (int) $field['ID'];

        if (!isset(self::$enumCache[$fieldId])) {
            self::$enumCache[$fieldId] = [];
            $rs = \CUserFieldEnum::GetList(['SORT' => 'ASC'], ['USER_FIELD_ID' => $fieldId]);
            while ($enum = $rs->Fetch()) {
                self::$enumCache[$fieldId][(int) $enum['ID']] = $enum;
            }
        }

        return self::$enumCache[$fieldId];
    }

    /**
     * Файлы всех записей одним запросом.
     */
    private static function warmFiles(array $rows, array $fields): void
    {
        $ids = [];
        foreach ($fields as $name => $field) {
            if ($field['USER_TYPE_ID'] === 'file') {
                foreach ($rows as $row) {
                    array_push($ids, ...(array) ($row[$name] ?? []));
                }
            }
        }

        if (!empty($ids)) {
            Content::warmFilesCache($ids);
        }
    }
}
