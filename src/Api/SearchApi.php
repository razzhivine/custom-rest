<?php

namespace Custom\Rest\Api;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * REST-методы поиска по сайту (модуль search): результаты и подсказки.
 * Ищет по индексу модуля, в который инфоблоки сами пишут элементы при
 * сохранении (настройка инфоблока «Индексировать элементы для модуля
 * поиска»; значения свойства — если у него включено «Участвует в поиске»).
 *
 * Модуль поиска фильтрует по правам владельца вебхука, а он может видеть
 * больше, чем посетитель. Поэтому поиск ограничен белым списком инфоблоков
 * и датами активности. Элементов и разделов из неактивных разделов в индексе
 * нет — см. Custom\Rest\Search\IndexRules.
 */
class SearchApi extends AbstractApi
{
    private const QUERY_MAX_LENGTH = 200;

    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    private const SUGGEST_DEFAULT_LIMIT = 5;

    private const SUGGEST_MAX_LIMIT = 10;

    /**
     * Порядок результатов, как у штатного компонента search.page:
     * ручная релевантность, релевантность поиска, затем свежие.
     */
    private const ORDER = ['CUSTOM_RANK' => 'DESC', 'RANK' => 'DESC', 'DATE_CHANGE' => 'DESC'];

    /**
     * @param array $params ['q' => string, 'iblock_code' => [API_CODE, …], 'page', 'limit']
     *
     * Результаты поиска по открытым инфоблокам (или по указанным из них)
     * одним списком по релевантности, с морфологией. У результата —
     * подсвеченный фрагмент текста (snippet, HTML с <b>) и url по шаблонам
     * инфоблока. Запрос из одних стоп-слов или слишком коротких слов —
     * пустой результат.
     */
    public static function search(array $params): array
    {
        return self::run(static function () use ($params): array {
            [$query, $filter, $iblocks] = self::prepare($params);
            [$page, $limit] = self::preparePagination($params, self::DEFAULT_LIMIT, self::MAX_LIMIT);

            $search = new \CSearch();
            // Пустой результат вместо ошибки, если в запросе нет значимых слов.
            $search->SetOptions(['ERROR_ON_EMPTY_STEM' => false]);
            $search->Search(['QUERY' => $query] + $filter, self::ORDER);

            // Запрос, из которого после разбора ничего не осталось (одни
            // стоп-слова, знаки), — пустой результат, а не ошибка: так его
            // проще показать в строке поиска. Причина — в meta.notice.
            if ($search->errorno != 0) {
                return [
                    'items' => [],
                    'meta' => ['total' => 0, 'page' => $page, 'limit' => $limit, 'pages' => 0,
                        'notice' => strip_tags((string) $search->error)],
                ];
            }

            $search->NavStart($limit, false, $page);

            $items = [];
            while ($row = $search->GetNext()) {
                $items[] = self::formatItem($row, $iblocks) + [
                    'snippet' => self::cleanSnippet((string) ($row['BODY_FORMATED'] ?? '')),
                    'date' => self::isoDate($row['FULL_DATE_CHANGE'] ?? $row['DATE_CHANGE'] ?? null),
                ];
            }

            $total = (int) $search->NavRecordCount;

            return [
                'items' => $items,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => (int) ceil($total / $limit),
                ],
            ];
        });
    }

    /**
     * @param array $params ['q' => string, 'iblock_code' => [API_CODE, …], 'limit']
     *
     * Подсказки для строки поиска при вводе: совпадения по началу слов
     * в названиях (штатный CSearchTitle, как у компонента search.title).
     * name_formatted — название с подсвеченным совпадением (HTML с <b>).
     */
    public static function suggest(array $params): array
    {
        return self::run(static function () use ($params): array {
            [$query, $filter, $iblocks] = self::prepare($params);
            $limit = min(self::SUGGEST_MAX_LIMIT, max(1, self::paramInt($params, 'limit', self::SUGGEST_DEFAULT_LIMIT)));

            $title = new \CSearchTitle();

            $items = [];
            if ($title->Search($query, $limit, $filter, false, 'rank')) {
                while (($row = $title->Fetch()) && count($items) < $limit) {
                    $items[] = self::formatItem($row, $iblocks) + [
                        'name_formatted' => (string) ($row['NAME'] ?? ''),
                    ];
                }
            }

            return ['items' => $items];
        });
    }

    /**
     * Запрос и фильтр для CSearch / CSearchTitle: только инфоблоки из белого
     * списка и проверка дат активности.
     *
     * @return array{0: string, 1: array, 2: array} [запрос, фильтр, [ID инфоблока => API_CODE]]
     */
    private static function prepare(array $params): array
    {
        if (!Loader::includeModule('search')) {
            throw new SystemException('Модуль поиска (search) не установлен');
        }

        $query = trim(self::paramString($params, 'q'));
        if ($query === '') {
            self::badRequest("Параметр 'q' обязателен");
        }

        if (mb_strlen($query) > self::QUERY_MAX_LENGTH) {
            self::badRequest('Поисковый запрос длиннее ' . self::QUERY_MAX_LENGTH . ' символов');
        }

        $iblocks = IblockApi::allowedIblocks(self::paramArray($params, 'iblock_code', []));

        $filter = [
            'SITE_ID' => self::siteId(),
            'MODULE_ID' => 'iblock',
            'PARAM2' => array_map('strval', array_keys($iblocks)),
            'CHECK_DATES' => 'Y',
        ];

        return [$query, $filter, $iblocks];
    }

    /**
     * Общие поля результата и подсказки: что найдено, где и ссылка.
     * URL индекс строит по шаблонам инфоблока — те же, что в остальном API.
     */
    private static function formatItem(array $row, array $iblocks): array
    {
        $itemId = (string) ($row['ITEM_ID'] ?? '');
        $isSection = str_starts_with($itemId, 'S');

        return [
            'type' => $isSection ? 'section' : 'element',
            'iblock_code' => $iblocks[(int) ($row['PARAM2'] ?? 0)] ?? null,
            'id' => (int) ($isSection ? substr($itemId, 1) : $itemId),
            'name' => (string) ($row['~TITLE'] ?? $row['TITLE'] ?? ''),
            // Без ?sphrase_id=… — метки статистики поисковых фраз.
            'url' => (string) ($row['~URL_WO_PARAMS'] ?? $row['~URL'] ?? $row['URL'] ?? ''),
        ];
    }

    /**
     * Индекс хранит текст с \r на месте бывших HTML-тегов и переносами
     * между полями (анонс, описание, свойства) — во фрагменте это лишнее.
     */
    private static function cleanSnippet(string $snippet): string
    {
        $snippet = str_replace("\r", '', $snippet);

        return trim(preg_replace('/\s+/u', ' ', $snippet));
    }

    private static function siteId(): string
    {
        return defined('SITE_ID') ? SITE_ID : (string) \CSite::GetDefSite();
    }

    private static function isoDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTime($value))->format('c');
        } catch (\Throwable) {
            return null;
        }
    }
}
