<?php

namespace Custom\Rest\Api;

use Custom\Rest\Config;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\RestException;
use Throwable;

/**
 * Общее для REST-методов всех модулей: обёртка вызова с единой обработкой
 * ошибок, ответы 400/404 и разбор параметров (filter, order, пагинация).
 * Методы модулей — в наследниках
 * (IblockApi, FormApi, SearchApi, HlApi), регистрация — в Registry.
 */
abstract class AbstractApi
{
    protected const FILTER_OPERATORS = '=<>!%*^$~@';

    /**
     * Операторы фильтра, которые принимает API. Остальные сочетания
     * символов (полнотекстовый *, ~, ^, $) ORM не понимает и падает —
     * клиенту вместо 500 отвечаем 400.
     */
    protected const ALLOWED_FILTER_OPERATORS = ['', '=', '!', '!=', '>', '>=', '<', '<=', '%', '!%', '@', '!@', '><', '!><'];

    protected const FILTER_MAX_DEPTH = 5;

    protected static function paramArray(array $params, string $key, array $default): array
    {
        return isset($params[$key]) && is_array($params[$key]) ? $params[$key] : $default;
    }

    /**
     * Строковый параметр; массив — 400 (иначе PHP превратил бы его в «Array»).
     */
    protected static function paramString(array $params, string $key, string $default = ''): string
    {
        $value = $params[$key] ?? $default;
        if (is_array($value)) {
            self::badRequest("Параметр '{$key}' должен быть строкой.");
        }

        return (string) $value;
    }

    /**
     * Целочисленный параметр; массив — 400 (иначе (int) дал бы 1).
     */
    protected static function paramInt(array $params, string $key, int $default = 0): int
    {
        $value = $params[$key] ?? $default;
        if (is_array($value)) {
            self::badRequest("Параметр '{$key}' должен быть числом.");
        }

        return (int) $value;
    }

    /**
     * Ошибка, текст которой предназначен клиенту: Bitrix отдаст её
     * как 400 Bad Request.
     */
    protected static function badRequest(string $message): never
    {
        throw new RestException($message, RestException::ERROR_ARGUMENT, \CRestServer::STATUS_WRONG_REQUEST);
    }

    protected static function notFound(string $message): never
    {
        throw new RestException($message, RestException::ERROR_NOT_FOUND, \CRestServer::STATUS_NOT_FOUND);
    }

    /**
     * Не в ответ REST — сообщения непредвиденных исключений (ORM/БД)
     * могут содержать текст SQL-запроса и прочие внутренние детали.
     */
    private static function logException(Throwable $e): void
    {
        $message = sprintf(
            '[%s] %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        // Журнал исключений Битрикса (exception_handling.log в .settings.php),
        // если он настроен, иначе — лог PHP (на стенде: docker compose logs php).
        if (!empty(Configuration::getValue('exception_handling')['log'])) {
            Application::getInstance()->getExceptionHandler()->writeToLog($e);
            return;
        }

        error_log('[custom.rest] ' . $message . "\n" . $e->getTraceAsString());
    }

    /**
     * Клиенту отдаются только RestException — их бросают проверки выше,
     * текст рассчитан на клиента. SystemException сюда не относится:
     * от него наследуются SqlQueryException и ArgumentException из ORM,
     * чьи сообщения содержат SQL и внутренние детали. Всё, что не
     * RestException, логируется и превращается в нейтральный 500.
     */
    private static function handleException(Throwable $e): never
    {
        if ($e instanceof RestException) {
            throw $e;
        }

        self::logException($e);

        throw new RestException(
            'Внутренняя ошибка сервера',
            RestException::ERROR_CORE,
            \CRestServer::STATUS_INTERNAL
        );
    }

    /**
     * Общая обёртка REST-метода: единая обработка ошибок. Свой модуль
     * Битрикса каждый наследник подключает сам — в обёртке не должно быть
     * зависимостей от конкретного модуля.
     */
    protected static function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (Throwable $e) {
            self::handleException($e);
        }
    }

    /**
     * Проверяет фильтр клиента и переводит его в фильтр ORM. Числовые
     * ключи — вложенные группы (AND/OR), проверяются рекурсивно: иначе
     * через группу можно было бы отфильтровать по любому полю, включая
     * связанные сущности (например, CREATED_BY_USER.*).
     *
     * $resolve(string $field, mixed $value): [поле ORM, значение, через
     * подзапрос] — поле клиента (без оператора, в верхнем регистре) в поле
     * ORM; недопустимое поле — self::badRequest() внутри $resolve.
     *
     * Третий элемент true — поле лежит в отдельной таблице (свойство,
     * множественное поле). Отрицание по нему ORM проверяет построчно:
     * элемент со значениями [a, b] проходит «!= a» из-за строки b, а
     * элемент без значений не проходит ничего (NULL). Поэтому «!» по
     * такому полю становится «ID не среди тех, где условие выполняется»:
     * $negate(array $condition) возвращает это условие для фильтра ORM
     * (см. self::notInSubquery).
     */
    protected static function prepareFilter(array $filter, callable $resolve, ?callable $negate = null, int $depth = 0): array
    {
        if ($depth > self::FILTER_MAX_DEPTH) {
            self::badRequest('Слишком глубокая вложенность фильтра.');
        }

        $result = [];

        foreach ($filter as $key => $value) {
            if (is_int($key)) {
                if (!is_array($value)) {
                    self::badRequest('Вложенная группа фильтра должна быть массивом.');
                }
                $result[] = self::prepareFilter($value, $resolve, $negate, $depth + 1);
                continue;
            }

            if (strtoupper($key) === 'LOGIC') {
                $logic = strtoupper((string) $value);
                if (!in_array($logic, ['AND', 'OR'], true)) {
                    self::badRequest("LOGIC в фильтре может быть только AND или OR.");
                }
                $result['LOGIC'] = $logic;
                continue;
            }

            $operator = substr($key, 0, strspn($key, self::FILTER_OPERATORS));
            if (!in_array($operator, self::ALLOWED_FILTER_OPERATORS, true)) {
                self::badRequest("Оператор '{$operator}' в фильтре не поддерживается.");
            }
            [$ormField, $ormValue, $bySubquery] = $resolve(strtoupper(substr($key, strlen($operator))), $value) + [2 => false];

            if ($bySubquery && $negate !== null && str_contains($operator, '!')) {
                // Отдельной группой: два отрицания на одном уровне не затрут друг друга.
                $result[] = $negate([str_replace('!', '', $operator) . $ormField => $ormValue]);
                continue;
            }

            $result[$operator . $ormField] = $ormValue;
        }

        return $result;
    }

    /**
     * Условие «ID не среди записей $entity, подходящих под $filter» для
     * фильтра ORM — NOT IN (подзапрос). Старый формат фильтра ORM
     * принимает подзапрос только объектом с compile(), а SqlExpression
     * не годится: он разбирает «?» в готовом SQL как плейсхолдеры.
     *
     * @param class-string<\Bitrix\Main\ORM\Data\DataManager> $entity
     */
    protected static function notInSubquery(string $entity, array $filter): array
    {
        $sql = $entity::query()->setSelect(['ID'])->setFilter($filter)->getQuery();

        return ['!@ID' => new class ($sql) {
            public function __construct(private readonly string $sql)
            {
            }

            public function compile(): string
            {
                return $this->sql;
            }
        }];
    }

    /**
     * Дата из фильтра клиента → объект для ORM. Принимает ISO 8601
     * (2026-10-01, 2026-10-01T10:00:00+03:00) — в нём API отдаёт даты —
     * и формат сайта (01.10.2026). Строкой ORM понимает только формат
     * сайта, а на остальном падает с ошибкой SQL. Неверная дата — 400.
     * Список дат — тоже 400: «одно из» по дате ядро не выполняет (ошибка
     * в CSQLWhere), диапазон задаётся через >= и <=. Пустое значение —
     * как есть.
     */
    protected static function filterDate(mixed $value, bool $withTime): mixed
    {
        if (is_array($value)) {
            self::badRequest('Список дат в фильтре не поддерживается: задайте диапазон через >= и <=.');
        }

        if ($value === null || $value === '') {
            return $value;
        }

        try {
            $date = new \DateTime((string) $value);
        } catch (\Throwable) {
            self::badRequest("Неверная дата '{$value}': нужен формат 2026-10-01 или 2026-10-01T10:00:00+03:00.");
        }

        return $withTime ? DateTime::createFromPhp($date) : Date::createFromPhp($date);
    }

    /**
     * Значение фильтра для числового поля (ID, число, привязка): число,
     * список чисел или пусто (условие «не заполнено»). Иначе — 400:
     * MySQL молча превратил бы «abc» в 0.
     */
    protected static function filterNumber(mixed $value, string $field): mixed
    {
        if (is_array($value)) {
            return array_map(static fn ($one) => self::filterNumber($one, $field), $value);
        }

        if ($value === null || $value === '') {
            return $value;
        }

        if (!is_numeric($value)) {
            self::badRequest("Поле '{$field}' в фильтре — число, а передано '{$value}'.");
        }

        return $value + 0;
    }

    protected static function prepareOrder(array $order, callable $resolve): array
    {
        $result = [];

        foreach ($order as $field => $direction) {
            $field = strtoupper((string) $field);
            $direction = is_string($direction) ? strtoupper($direction) : '';

            $ormField = $resolve($field);

            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                self::badRequest("Направление сортировки по полю '{$field}' должно быть ASC или DESC.");
            }

            $result[$ormField] = $direction;
        }

        return $result;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [page, limit, offset]
     */
    protected static function preparePagination(array $params, int $defaultLimit, int $maxLimit): array
    {
        $page = max(1, self::paramInt($params, 'page', 1));
        $limit = min($maxLimit, max(1, self::paramInt($params, 'limit', $defaultLimit)));

        return [$page, $limit, ($page - 1) * $limit];
    }

    protected static function paginationMeta(int $total, int $page, int $limit): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ];
    }
}
