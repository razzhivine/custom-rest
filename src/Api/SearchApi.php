<?php

namespace Custom\Rest\Api;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
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

    /** Слова короче не исправляются: у них слишком много «похожих» слов. */
    private const CORRECT_MIN_LENGTH = 4;

    /** Сколько секунд живёт словарь основ слов индекса. */
    private const DICTIONARY_TTL = 300;

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
     * @param array $params ['q' => string]
     *
     * Исправление опечаток в запросе: «басейн» → «бассейн». Поиск Битрикса ищет по основам слов и опечаток
     * не прощает, поэтому каждое слово запроса, которого нет в индексе, заменяется ближайшей основой слова
     * из индекса (расстояние Дамерау — Левенштейна: 1 ошибка у слов из 4–7 букв, до 2 у более длинных;
     * при равенстве — основа, которая встречается в большем числе материалов). Слова, которые в индексе
     * есть, и слова короче CORRECT_MIN_LENGTH не меняются. Исправлять нечего или не нашлось похожих —
     * corrected = null. Исправленный запрос передают в search как обычный.
     *
     * @return array{query: string, corrected: ?string}
     */
    public static function correct(array $params): array
    {
        return self::run(static function () use ($params): array {
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

            $dictionary = self::dictionary();
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $changed = false;
            $result = [];
            foreach ($words as $word) {
                $fixed = self::correctWord($word, $dictionary);
                $changed = $changed || $fixed !== $word;
                $result[] = $fixed;
            }

            return ['query' => $query, 'corrected' => $changed ? implode(' ', $result) : null];
        });
    }

    /**
     * Ближайшая к слову основа из словаря; само слово, если оно уже есть в индексе, слишком короткое
     * или похожего не нашлось.
     *
     * @param array<string, int> $dictionary основа (в верхнем регистре) => число материалов
     */
    private static function correctWord(string $word, array $dictionary): string
    {
        $length = mb_strlen($word);
        if ($length < self::CORRECT_MIN_LENGTH) {
            return $word;
        }

        // Основы самого слова (Битрикс приводит словоформы к основе): если любая из них есть в индексе — слово верное.
        $stems = function_exists('stemming') ? array_keys((array) stemming($word, 'ru')) : [];
        $stems = $stems ?: [mb_strtoupper($word)];
        foreach ($stems as $stem) {
            if (isset($dictionary[$stem])) {
                return $word;
            }
        }

        $maxDistance = $length >= 8 ? 2 : 1;
        $best = null;
        $bestOwnStem = '';
        $bestDistance = $maxDistance + 1;
        $bestDocs = 0;

        foreach ($stems as $stem) {
            $stemLength = mb_strlen($stem);
            foreach ($dictionary as $candidate => $docs) {
                $candidate = (string) $candidate;
                if (abs(mb_strlen($candidate) - $stemLength) > $maxDistance) {
                    continue;
                }

                $distance = self::distance($stem, $candidate, $bestDistance);
                if ($distance < $bestDistance || ($distance === $bestDistance && $docs > $bestDocs)) {
                    $best = $candidate;
                    $bestOwnStem = $stem;
                    $bestDistance = $distance;
                    $bestDocs = $docs;
                }
            }
        }

        if ($best === null || $bestDistance > $maxDistance) {
            return $word;
        }

        // Исправляется основа слова, а окончание остаётся своим: «тренажорный» → «тренажерный», а не «тренажерн».
        $suffix = '';
        $upper = mb_strtoupper($word);
        if ($bestOwnStem !== '' && str_starts_with($upper, $bestOwnStem)) {
            $suffix = mb_strtolower(mb_substr($upper, mb_strlen($bestOwnStem)));
        }

        return mb_strtolower($best) . $suffix;
    }

    /**
     * Расстояние Дамерау — Левенштейна (вставка, удаление, замена, перестановка соседних букв).
     * Если оно заведомо больше $limit, возвращается $limit + 1 — дальше считать незачем.
     */
    private static function distance(string $a, string $b, int $limit): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $n = count($a);
        $m = count($b);
        if (abs($n - $m) > $limit) {
            return $limit + 1;
        }

        $previous2 = [];
        $previous = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $value = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $value = min($value, $previous2[$j - 2] + 1);
                }
                $current[$j] = $value;
                $rowMin = min($rowMin, $value);
            }
            if ($rowMin > $limit) {
                return $limit + 1;
            }
            $previous2 = $previous;
            $previous = $current;
        }

        return $previous[$m];
    }

    /**
     * Словарь индекса поиска: основы слов (как их хранит модуль, в верхнем регистре) и число материалов,
     * в которых они встречаются. Живёт несколько минут: новые материалы попадают в него вместе с индексом.
     *
     * @return array<string, int>
     */
    private static function dictionary(): array
    {
        $cache = Cache::createInstance();
        if ($cache->initCache(self::DICTIONARY_TTL, 'custom_rest_search_dictionary', '/custom_rest/search')) {
            return $cache->getVars();
        }

        $cache->startDataCache();
        $rows = Application::getConnection()->query(
            "SELECT s.STEM AS STEM, COUNT(DISTINCT c.SEARCH_CONTENT_ID) AS DOCS
             FROM b_search_content_stem c
             JOIN b_search_stem s ON s.ID = c.STEM
             WHERE c.LANGUAGE_ID = 'ru'
             GROUP BY s.STEM"
        );
        $dictionary = [];
        while ($row = $rows->fetch()) {
            $dictionary[(string) $row['STEM']] = (int) $row['DOCS'];
        }
        $cache->endDataCache($dictionary);

        return $dictionary;
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
