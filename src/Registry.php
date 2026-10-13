<?php

namespace Custom\Rest;

use Bitrix\Main\EventManager;
use Custom\Rest\Api\FormApi;
use Custom\Rest\Api\HlApi;
use Custom\Rest\Api\IblockApi;
use Custom\Rest\Api\SearchApi;
use Custom\Rest\Api\SitemapApi;

/**
 * Регистрация REST-методов в Битриксе. Сами методы — в классах по модулям:
 * IblockApi (инфоблоки), FormApi (веб-формы), SearchApi (поиск), HlApi
 * (highload-блоки), SitemapApi (карта сайта).
 *
 * Имя метода — <префикс>.<название>, например custom.rest.element.get;
 * префикс и название права (scope) — в настройках (Config::prefix(),
 * Config::scope()).
 */
class Registry
{
    /**
     * Методы без префикса: название => [класс, метод].
     */
    public const METHODS = [
        'iblock.get' => [IblockApi::class, 'getIblock'],
        'element.get' => [IblockApi::class, 'getElement'],
        'element.list' => [IblockApi::class, 'getList'],
        'section.get' => [IblockApi::class, 'getSection'],
        'section.list' => [IblockApi::class, 'getSections'],
        'section.tree' => [IblockApi::class, 'getSectionTree'],
        'breadcrumbs.get' => [IblockApi::class, 'getBreadcrumbs'],
        'filter.get' => [IblockApi::class, 'getFilter'],
        'form.get' => [FormApi::class, 'getForm'],
        'form.add' => [FormApi::class, 'addResult'],
        'captcha.get' => [FormApi::class, 'getCaptcha'],
        'search' => [SearchApi::class, 'search'],
        'search.suggest' => [SearchApi::class, 'suggest'],
        'search.correct' => [SearchApi::class, 'correct'],
        'hl.list' => [HlApi::class, 'getList'],
        'hl.get' => [HlApi::class, 'getItem'],
        'sitemap.index' => [SitemapApi::class, 'getIndex'],
        'sitemap.get' => [SitemapApi::class, 'getPage'],
    ];

    public static function register(): void
    {
        EventManager::getInstance()->addEventHandler(
            'rest',
            'onRestServiceBuildDescription',
            [self::class, 'onRestServiceBuildDescription']
        );
    }

    /**
     * Свой scope: во входящем вебхуке права выдаются только на этот API,
     * без доступа к стандартным методам модуля iblock.
     */
    public static function onRestServiceBuildDescription(): array
    {
        $prefix = Config::prefix();

        $methods = [];
        foreach (self::METHODS as $name => $callback) {
            $methods[$prefix . '.' . $name] = ['callback' => $callback];
        }

        return [Config::scope() => $methods];
    }
}
