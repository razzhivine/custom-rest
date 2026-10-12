<?php

namespace Custom\Rest\Search;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;

/**
 * Правила поискового индекса для REST API: элементы и разделы инфоблоков
 * из неактивного раздела (сам или через родителя) в индексе не хранятся.
 *
 * Модуль инфоблоков индексирует их, а публичный поиск и API не должны их
 * отдавать. Раньше API передавал в запрос список ID всего скрытого — на
 * тысячах элементов запрос рос бы вместе с ним. Теперь скрытого в индексе
 * просто нет.
 *
 * После смены активности раздела индекс его поддерева пересобирается.
 * Существующий индекс — один раз: «Настройки → Поиск → Переиндексация».
 */
class IndexRules
{
    public static function register(): void
    {
        $events = EventManager::getInstance();
        $events->addEventHandler('search', 'BeforeIndex', [self::class, 'onBeforeIndex']);
        $events->addEventHandler('iblock', 'OnAfterIBlockSectionUpdate', [self::class, 'onSectionUpdate']);
    }

    /**
     * Пустые заголовок и текст — штатный способ модуля поиска убрать запись
     * из индекса (или не добавлять её).
     */
    public static function onBeforeIndex(array $fields): array|false
    {
        if (($fields['MODULE_ID'] ?? '') !== 'iblock' || !Loader::includeModule('iblock')) {
            return false;
        }

        $itemId = (string) ($fields['ITEM_ID'] ?? '');
        if (!self::isHidden($itemId)) {
            return false;
        }

        $fields['TITLE'] = '';
        $fields['BODY'] = '';

        return $fields;
    }

    /**
     * Скрыт ли элемент ('123') или раздел ('S45') неактивным разделом.
     * Корень инфоблока скрыть нельзя.
     */
    private static function isHidden(string $itemId): bool
    {
        if (str_starts_with($itemId, 'S')) {
            $sectionId = (int) substr($itemId, 1);
        } else {
            $element = ElementTable::getList([
                'select' => ['IBLOCK_SECTION_ID'],
                'filter' => ['=ID' => (int) $itemId],
                'limit' => 1,
            ])->fetch();
            $sectionId = (int) ($element['IBLOCK_SECTION_ID'] ?? 0);
        }

        if ($sectionId <= 0) {
            return false;
        }

        $section = SectionTable::getList([
            'select' => ['GLOBAL_ACTIVE'],
            'filter' => ['=ID' => $sectionId],
            'limit' => 1,
        ])->fetch();

        return $section !== false && $section['GLOBAL_ACTIVE'] === 'N';
    }

    /**
     * Активность раздела изменилась (или могла измениться) — переиндексируем
     * его поддерево: разделы и элементы, у которых он основной или предок.
     */
    public static function onSectionUpdate(array $fields): void
    {
        if (!array_key_exists('ACTIVE', $fields) || empty($fields['ID']) || !Loader::includeModule('iblock')) {
            return;
        }

        $section = SectionTable::getList([
            'select' => ['IBLOCK_ID', 'LEFT_MARGIN', 'RIGHT_MARGIN'],
            'filter' => ['=ID' => (int) $fields['ID']],
            'limit' => 1,
        ])->fetch();
        if (!$section) {
            return;
        }

        $sections = SectionTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=IBLOCK_ID' => $section['IBLOCK_ID'],
                '>=LEFT_MARGIN' => $section['LEFT_MARGIN'],
                '<=RIGHT_MARGIN' => $section['RIGHT_MARGIN'],
            ],
        ])->fetchAll();

        $sectionIndexer = new \CIBlockSection();
        foreach ($sections as $row) {
            $sectionIndexer->UpdateSearch((int) $row['ID'], true);
        }

        $elements = ElementTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=IBLOCK_ID' => $section['IBLOCK_ID'],
                '>=IBLOCK_SECTION.LEFT_MARGIN' => $section['LEFT_MARGIN'],
                '<=IBLOCK_SECTION.RIGHT_MARGIN' => $section['RIGHT_MARGIN'],
            ],
        ])->fetchAll();

        foreach ($elements as $row) {
            \CIBlockElement::UpdateSearch((int) $row['ID'], true);
        }
    }
}
