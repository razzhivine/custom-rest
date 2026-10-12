#!/usr/bin/env python3
"""Собирает описание REST API пакета custom/rest в формате OpenAPI 3.0 -> docs/openapi.json.

Запуск из папки пакета:   python3 tools/openapi.py
Проверка без записи файла: python3 tools/openapi.py --check  (код возврата 1, если файл устарел или схема неверна)
Другой префикс методов:    python3 tools/openapi.py --prefix shop --out /путь/openapi.json
                           (префикс — ключ prefix в настройках проекта; по умолчанию custom.rest)

Описание — здесь, в словарях ниже: правите методы или схемы, запускаете скрипт, коммитите docs/openapi.json. Скрипт сам
проверяет ссылки ($ref), уникальность operationId и то, что каждый путь описан. Соответствие списку методов, которые реально
зарегистрированы в Битриксе, проверяет tests/run.sh.
"""
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_OUT = os.path.join(ROOT, 'docs', 'openapi.json')


def option(name, default):
    """Значение ключа командной строки вида --name значение."""
    if name in sys.argv:
        i = sys.argv.index(name)
        if i + 1 < len(sys.argv):
            return sys.argv[i + 1]
    return default


PREFIX = option('--prefix', 'custom.rest')
OUT = option('--out', DEFAULT_OUT)


# ---------- помощники ----------

def ref(name):
    return {'$ref': '#/components/schemas/' + name}


def nullable_ref(name):
    # В OpenAPI 3.0 nullable рядом с $ref игнорируется, поэтому через allOf.
    return {'allOf': [ref(name)], 'nullable': True}


def obj(props=None, required=None, extra=True, description=None):
    s = {'type': 'object', 'properties': props or {}}
    if required:
        s['required'] = required
    if extra:
        s['additionalProperties'] = True
    if description:
        s['description'] = description
    return s


def arr(items, description=None):
    s = {'type': 'array', 'items': items}
    if description:
        s['description'] = description
    return s


STR = {'type': 'string'}
INT = {'type': 'integer'}
BOOL = {'type': 'boolean'}
NULLABLE_STR = {'type': 'string', 'nullable': True}


def param(name, schema, description, required=False, style=None, explode=None):
    p = {'name': name, 'in': 'query', 'required': required, 'description': description, 'schema': schema}
    if style:
        p['style'] = style
    if explode is not None:
        p['explode'] = explode
    return p


def pref(name):
    return {'$ref': '#/components/parameters/' + name}


def ok(schema, description='Успех'):
    """Ответ 200: результат в result, служебное время в time."""
    return {
        'description': description,
        'content': {'application/json': {'schema': obj({'result': schema, 'time': ref('Time')}, ['result'])}},
    }


def errors(*codes):
    names = {'400': 'BadRequest', '404': 'NotFound', '429': 'TooManyRequests', '401': 'Unauthorized'}
    result = {}
    for code in codes + ('401', '500'):
        result[code] = {'$ref': '#/components/responses/' + {**names, '500': 'ServerError'}[code]}
    return result


def op(method, tag, summary, description, parameters, result, error_codes=('400',), request_body=None):
    operation = {
        'operationId': ''.join(part.capitalize() if i else part for i, part in enumerate(method.replace('.', '_').split('_'))),
        'tags': [tag],
        'summary': summary,
        'description': description,
        'parameters': parameters,
        'responses': {'200': ok(result), **errors(*error_codes)},
    }
    if method in CACHED:
        operation['parameters'] = operation['parameters'] + CONDITIONAL_PARAMS
        operation['responses']['200']['headers'] = VALIDATOR_HEADERS
        operation['responses']['304'] = {'description': 'Не изменилось с прошлого запроса: тело пустое, берите ответ из своего кеша.'}
    if request_body:
        operation['requestBody'] = request_body
        del operation['parameters']
        verb = 'post'
    else:
        verb = 'get'
    return '/' + PREFIX + '.' + method + '.json', {verb: operation}


# ---------- общие параметры ----------

PARAMETERS = {
    'iblock_code': param('iblock_code', STR, 'Символьный код API инфоблока (API_CODE) из белого списка настроек API.', True),
    'select': param('select[]', arr(STR), 'Какие поля вернуть: `select[]=NAME&select[]=CODE`. `ID` и `IBLOCK_ID` приходят всегда.', False, 'form', True),
    'filter': param('filter', obj(), 'Условия D7: ключ — оператор и поле, значение — с чем сравнивать. Например `filter[>=PROPERTY.AREA]=1000`, '
                                     '`filter[@ID][]=1&filter[@ID][]=2`, `filter[0][LOGIC]=OR&filter[0][CODE]=a`. Операторы: `=` `!` `!=` `>` `>=` `<` `<=` `%` `!%` `@` `!@` `><` `!><`. '
                                     'Поля — из белого списка метода, свойства — `PROPERTY.<КОД>`.', False, 'deepObject', True),
    'order': param('order', obj(), 'Сортировка: поле → `ASC` или `DESC`, например `order[NAME]=asc` или `order[PROPERTY.AREA]=desc`.', False, 'deepObject', True),
    'properties': param('properties[]', arr(STR), 'Свойства элемента, которые вернуть (только из `read_properties` инфоблока): `properties[]=FORMAT&properties[]=AREA`.', False, 'form', True),
    'page': param('page', {'type': 'integer', 'minimum': 1, 'default': 1}, 'Номер страницы.'),
    'limit': param('limit', {'type': 'integer', 'minimum': 1}, 'Сколько записей на страницу (максимум зависит от метода).'),
    'section_id': param('section_id', INT, 'ID раздела. Либо он, либо `section_code_path`.'),
    'section_code_path': param('section_code_path', STR, 'Путь кодов раздела от корня, как в URL: `moscow/moscow-center`.'),
    'include_subsections': param('include_subsections', {'type': 'string', 'enum': ['Y']}, '`Y` — вместе с подразделами.'),
}

P = pref

# ---------- схемы ----------

SCHEMAS = {
    'Time': obj({'start': {'type': 'number'}, 'finish': {'type': 'number'}, 'duration': {'type': 'number'},
                 'processing': {'type': 'number'}, 'date_start': STR, 'date_finish': STR},
                description='Служебное время выполнения от Битрикса.'),
    'Error': obj({'error': STR, 'error_description': STR}, ['error', 'error_description']),
    'File': obj({
        'id': INT, 'src': STR, 'full_path': {'type': 'string', 'description': 'Абсолютная ссылка (по base_url или хосту сайта).'},
        'width': INT, 'height': INT, 'size': INT, 'alt': STR, 'title': STR,
    }, description='Файл или картинка.'),
    'Seo': obj({'title': STR, 'description': STR, 'keywords': STR, 'h1': STR},
               description='Вычисленные SEO-значения (в списках — только h1).'),
    'Breadcrumb': obj({
        'type': {'type': 'string', 'enum': ['home', 'iblock', 'section', 'element']},
        'id': {'type': 'integer', 'nullable': True}, 'name': STR, 'code': NULLABLE_STR,
        'url': {'type': 'string', 'nullable': True, 'description': 'null, если у инфоблока нет шаблона URL.'},
    }, ['type', 'name', 'url']),
    'Text': obj({'value': STR, 'type': {'type': 'string', 'enum': ['text', 'html']}},
                description='Текст с типом: `html` выводится как HTML, `text` — как обычный текст.'),
    'Breadcrumbs': arr(ref('Breadcrumb'), 'Главная → инфоблок → разделы → элемент.'),
    'PageMeta': obj({'total': INT, 'page': INT, 'limit': INT, 'pages': INT, 'breadcrumbs': ref('Breadcrumbs')},
                    description='Пагинация и крошки до раздела (или до инфоблока).'),
    'Element': obj({
        'id': INT, 'iblock_id': INT, 'name': STR, 'code': STR, 'date_create': STR,
        'preview_picture': nullable_ref('File'), 'detail_picture': nullable_ref('File'), 'preview_text': ref('Text'), 'detail_text': ref('Text'),
        'seo': ref('Seo'),
        'properties': obj(description='Значения запрошенных свойств по коду в нижнем регистре. Список — {name, value}; число; строка; '
                                      'HTML — {value, type}; файл — File; привязка — ID видимого; справочник — UF_XML_ID.'),
        'url': NULLABLE_STR, 'breadcrumbs': ref('Breadcrumbs'),
    }, description='Набор полей зависит от `select`; `id`, `iblock_id`, `seo` и `url` есть всегда.'),
    'Section': obj({
        'id': INT, 'iblock_id': INT, 'iblock_section_id': {'type': 'integer', 'nullable': True}, 'name': STR, 'code': STR, 'sort': INT,
        'picture': nullable_ref('File'), 'detail_picture': nullable_ref('File'), 'description': ref('Text'), 'seo': ref('Seo'), 'url': NULLABLE_STR,
        'breadcrumbs': ref('Breadcrumbs'),
    }, description='Набор полей зависит от `select`.'),
    'SectionNode': obj({
        'id': INT, 'iblock_id': INT, 'name': STR, 'code': STR, 'sort': INT, 'seo': ref('Seo'), 'url': NULLABLE_STR,
        'children': arr({'$ref': '#/components/schemas/SectionNode'}),
    }),
    'ElementList': obj({'items': arr(ref('Element')), 'meta': ref('PageMeta')}, ['items', 'meta']),
    'SectionList': obj({'items': arr(ref('Section')), 'meta': ref('PageMeta')}, ['items', 'meta']),
    'SectionTree': obj({
        'items': arr(ref('SectionNode')),
        'meta': obj({'total': INT, 'depth': INT, 'truncated': {'type': 'boolean', 'description': 'true — разделов больше 1000, дерево обрезано.'},
                     'breadcrumbs': ref('Breadcrumbs')}),
    }, ['items', 'meta']),
    'Iblock': obj({
        'id': INT, 'api_code': STR, 'code': STR, 'name': STR, 'description': ref('Text'),
        'picture': nullable_ref('File'), 'seo': ref('Seo'), 'url': NULLABLE_STR, 'breadcrumbs': ref('Breadcrumbs'),
    }),
    'FilterValue': obj({'value': {'description': 'XML_ID значения, ID привязки или строка.', 'oneOf': [STR, INT]},
                        'name': STR, 'count': INT, 'selected': BOOL}),
    'FilterItem': obj({
        'code': STR, 'name': STR,
        'type': {'type': 'string', 'enum': ['list', 'number', 'string', 'element', 'section', 'directory']},
        'multiple': BOOL,
        'display_type': {'type': 'string', 'nullable': True, 'enum': ['range_slider', 'range', 'checkbox', 'checkbox_pictures',
                                                                      'checkbox_pictures_names', 'radio', 'select', 'select_pictures_names', 'calendar']},
        'expanded': BOOL, 'hint': STR, 'values': arr(ref('FilterValue')),
        'range': obj({'min': {'type': 'number'}, 'max': {'type': 'number'}}, description='Для числа: весь диапазон.'),
        'available': obj({'min': {'type': 'number'}, 'max': {'type': 'number'}}, description='Для числа: диапазон при текущем выборе.'),
        'selected': obj({'min': {'type': 'number', 'nullable': True}, 'max': {'type': 'number', 'nullable': True}}),
    }, description='Свойство формы фильтра. У числа — range/available/selected, у остальных — values.'),
    'FilterForm': obj({'items': arr(ref('FilterItem')),
                       'meta': obj({'total': INT, 'breadcrumbs': ref('Breadcrumbs')}, description='total — сколько элементов подходит под весь выбор.')},
                      ['items', 'meta']),
    'FormControl': obj({
        'type': {'type': 'string', 'description': 'text, textarea, email, url, password, hidden, date, file, image, radio, dropdown, checkbox, multiselect.'},
        'name': {'type': 'string', 'description': 'Ключ для values в form.add.'}, 'label': STR, 'default': STR, 'multiple': BOOL,
        'options': arr(obj({'value': INT, 'name': STR, 'selected': BOOL}), 'Для выбора из вариантов.'),
    }),
    'FormField': obj({
        'sid': STR, 'title': ref('Text'), 'required': BOOL, 'comment': STR,
        'image': nullable_ref('File'), 'controls': arr(ref('FormControl')), 'validators': arr(obj({'name': STR, 'params': obj()})),
    }),
    'Form': obj({
        'id': INT, 'sid': STR, 'name': STR, 'description': ref('Text'),
        'button': STR, 'use_captcha': BOOL, 'image': nullable_ref('File'), 'fields': arr(ref('FormField')),
    }),
    'FormAddRequest': obj({
        'sid': {'type': 'string', 'description': 'SID формы из белого списка.'},
        'values': obj(description='Значения по name из form.get: строка, value варианта, массив value или файл ["имя.pdf", "<base64>"].'),
        'captcha_sid': STR, 'captcha_word': STR,
        'hp': {'type': 'string', 'description': 'Поле-ловушка для ботов: невидимое на фронте. Если заполнено, ответ успешный, но ничего не сохраняется.'},
    }, ['sid', 'values']),
    'FormAddResult': obj({
        'success': BOOL,
        'result_id': {'type': 'integer', 'description': '0 — сработала ловушка hp.'},
        'errors': arr(obj({'field': {'type': 'string', 'nullable': True, 'description': 'SID вопроса, captcha или null.'}, 'message': STR})),
    }, ['success'], description='Ошибки заполнения приходят как success=false с кодом 200, а не как HTTP-ошибка.'),
    'Captcha': obj({'sid': STR, 'src': STR, 'full_path': STR, 'width': INT, 'height': INT}),
    'SearchItem': obj({
        'type': {'type': 'string', 'enum': ['element', 'section']}, 'iblock_code': NULLABLE_STR, 'id': INT, 'name': STR, 'url': STR,
        'snippet': {'type': 'string', 'description': 'Фрагмент текста с подсветкой (<b>).'}, 'date': NULLABLE_STR,
        'name_formatted': {'type': 'string', 'description': 'Только у подсказок: название с подсветкой совпадения.'},
    }),
    'SearchResult': obj({
        'items': arr(ref('SearchItem')),
        'meta': obj({'total': INT, 'page': INT, 'limit': INT, 'pages': INT, 'notice': {'type': 'string', 'description': 'Почему результат пуст (например, запрос без букв).'}}),
    }, ['items']),
    'HlItem': obj({'id': INT}, ['id'], description='Поля — из белого списка блока в нижнем регистре: список — {name, value}, «да/нет» — boolean, '
                                                  'дата — Y-m-d, файл — File, множественное — массив.'),
    'HlList': obj({'items': arr(ref('HlItem')), 'meta': ref('PageMeta')}, ['items', 'meta']),
    'SitemapIndexItem': obj({
        'name': {'type': 'string', 'description': 'Код инфоблока или static.'}, 'type': {'type': 'string', 'enum': ['iblock', 'static']},
        'total': INT, 'pages': INT, 'lastmod': NULLABLE_STR,
    }),
    'SitemapIndex': obj({'items': arr(ref('SitemapIndexItem')), 'meta': obj({'total': INT, 'limit': INT})}, ['items']),
    'SitemapPageItem': obj({
        'url': STR, 'lastmod': NULLABLE_STR, 'type': {'type': 'string', 'enum': ['iblock', 'section', 'element', 'static']},
        'images': arr(STR, 'Только при images=Y.'),
    }, ['url', 'type']),
    'SitemapPage': obj({'items': arr(ref('SitemapPageItem')), 'meta': obj({'total': INT, 'page': INT, 'limit': INT, 'pages': INT})}, ['items']),
}

RESPONSES = {
    'BadRequest': {'description': 'Неверные параметры: нет обязательного, инфоблок или поле не разрешены, неверный фильтр.',
                   'content': {'application/json': {'schema': ref('Error')}}},
    'Unauthorized': {'description': 'Неверный адрес вебхука или у него нет права `' + PREFIX + '`.',
                     'content': {'application/json': {'schema': ref('Error')}}},
    'NotFound': {'description': 'Не найдено, неактивно или скрыто.', 'content': {'application/json': {'schema': ref('Error')}}},
    'TooManyRequests': {'description': 'Превышен лимит (отправки формы, капчи).', 'content': {'application/json': {'schema': ref('Error')}}},
    'ServerError': {'description': 'Внутренняя ошибка: подробности только в журнале Битрикса.', 'content': {'application/json': {'schema': ref('Error')}}},
}

# ---------- методы ----------

# Методы с кешем: отдают ETag и Last-Modified и отвечают 304 на условный GET.
CACHED = {'iblock.get', 'element.get', 'element.list', 'section.get', 'section.list', 'section.tree',
          'breadcrumbs.get', 'filter.get', 'sitemap.index', 'sitemap.get'}

VALIDATOR_HEADERS = {
    'ETag': {'description': 'Слабый ETag ответа (хеш `result`): тот же ответ даёт тот же ETag.', 'schema': STR},
    'Last-Modified': {'description': 'Когда построен закешированный ответ; правка контента сбрасывает кеш и сдвигает время вперёд.', 'schema': STR},
}

CONDITIONAL_PARAMS = [
    {'name': 'If-None-Match', 'in': 'header', 'required': False, 'schema': STR,
     'description': 'ETag из прошлого ответа. Если ответ не изменился — 304 без тела. Только для GET.'},
    {'name': 'If-Modified-Since', 'in': 'header', 'required': False, 'schema': STR,
     'description': 'Last-Modified из прошлого ответа (проверяется, если нет If-None-Match). Только для GET.'},
]

IBLOCKS, SEARCH, FORMS, HL, MAP = 'Инфоблоки', 'Поиск', 'Веб-формы', 'Highload-блоки', 'Карта сайта'

ELEMENT_LOOKUP = [
    param('id', INT, 'ID элемента. Либо он, либо `code`.'),
    param('code', STR, 'Символьный код элемента. Либо он, либо `id`.'),
    param('section_code_path', STR, 'Путь основного раздела из URL: код однозначен, неканонический путь даёт 404.'),
]

PATHS = {}


def add(entry):
    path, item = entry
    PATHS[path] = item


add(op('iblock.get', IBLOCKS, 'Инфоблок как страница списка',
       'Название, описание, картинка, SEO по шаблонам «Для разделов» инфоблока, адрес списка и хлебные крошки: корень каталога.',
       [P('iblock_code')], ref('Iblock'), ('400',)))
add(op('element.get', IBLOCKS, 'Один элемент',
       'Элемент с SEO, картинками, `url`, хлебными крошками и свойствами из `properties[]`. Неактивный, вне дат и из скрытого раздела — 404.',
       [P('iblock_code'), *ELEMENT_LOOKUP, P('select'), P('properties')], ref('Element'), ('400', '404')))
add(op('element.list', IBLOCKS, 'Список элементов',
       'Видимые элементы раздела или инфоблока: фильтр по полям и свойствам, сортировка, страницы. Крошки — в `meta.breadcrumbs`.',
       [P('iblock_code'), P('section_id'), P('section_code_path'), P('include_subsections'), P('select'), P('filter'), P('order'),
        P('properties'), P('page'), param('limit', {'type': 'integer', 'minimum': 1, 'maximum': 50, 'default': 10}, 'Записей на страницу.')],
       ref('ElementList'), ('400', '404')))
add(op('section.get', IBLOCKS, 'Один раздел', 'Раздел по `id`, `code` или `code_path` с SEO, картинками, `url` и крошками.',
       [P('iblock_code'), param('id', INT, 'ID раздела.'), param('code', STR, 'Код раздела (при повторах берётся первый по дереву).'),
        param('code_path', STR, 'Путь кодов от корня: `moscow/moscow-center`.'), P('select'), P('filter')],
       ref('Section'), ('400', '404')))
add(op('section.list', IBLOCKS, 'Дочерние разделы', 'Разделы первого уровня или потомки указанного, постранично. Скрытые не отдаются.',
       [P('iblock_code'), P('section_id'), P('section_code_path'), P('select'), P('filter'), P('order'), P('page'),
        param('limit', {'type': 'integer', 'minimum': 1, 'maximum': 200, 'default': 50}, 'Записей на страницу.')],
       ref('SectionList'), ('400', '404')))
add(op('section.tree', IBLOCKS, 'Дерево разделов', 'Дерево видимых разделов одним запросом (до 1000 узлов); `children` у каждого узла.',
       [P('iblock_code'), P('section_id'), P('section_code_path'),
        param('depth', {'type': 'integer', 'minimum': 1, 'maximum': 10, 'default': 3}, 'Сколько уровней вниз.'),
        P('select'), P('filter')], ref('SectionTree'), ('400', '404')))
add(op('breadcrumbs.get', IBLOCKS, 'Хлебные крошки',
       'Только цепочка до раздела или элемента, когда сами данные страницы не нужны.',
       [P('iblock_code'), P('section_id'), P('section_code_path'), param('element_id', INT, 'ID элемента.'),
        param('element_code', STR, 'Код элемента (вместе с `section_code_path` для однозначности).')],
       ref('Breadcrumbs'), ('400', '404')))
add(op('filter.get', IBLOCKS, 'Форма фильтра',
       'Свойства умного фильтра раздела: значения со счётчиками при текущем выборе и диапазоны чисел. Выбор — как в `filter` у `element.list`.',
       [P('iblock_code'), P('section_id'), P('section_code_path'), P('include_subsections'),
        param('properties[]', arr(STR), 'Свойства формы в нужном порядке (из `read_properties` и умного фильтра). Без него — по флагу умного фильтра.', False, 'form', True),
        P('filter')], ref('FilterForm'), ('400', '404')))

add(op('search', SEARCH, 'Поиск по сайту',
       'Результаты по индексу модуля поиска, только инфоблоки из белого списка; запрос без значимых слов даёт пустой результат с `meta.notice`.',
       [param('q', STR, 'Запрос, до 200 символов.', True),
        param('iblock_code[]', arr(STR), 'Ограничить инфоблоками из белого списка.', False, 'form', True), P('page'),
        param('limit', {'type': 'integer', 'minimum': 1, 'maximum': 50, 'default': 10}, 'Записей на страницу.')],
       ref('SearchResult'), ('400',)))
add(op('search.suggest', SEARCH, 'Подсказки поиска', 'Совпадения по началу слов в названиях, для строки поиска.',
       [param('q', STR, 'Начало запроса.', True), param('iblock_code[]', arr(STR), 'Ограничить инфоблоками.', False, 'form', True),
        param('limit', {'type': 'integer', 'minimum': 1, 'maximum': 10, 'default': 5}, 'Сколько подсказок.')],
       obj({'items': arr(ref('SearchItem'))}, ['items']), ('400',)))

add(op('form.get', FORMS, 'Веб-форма', 'Поля формы, варианты ответов, валидаторы и `name` для отправки; служебные поля и выключенные вопросы не отдаются.',
       [param('sid', STR, 'Символьный идентификатор (SID) формы из белого списка.', True)], ref('Form'), ('400', '404')))
add(op('form.add', FORMS, 'Отправить форму',
       'Проверка (обязательные поля, e-mail, даты, файлы, валидаторы, капча), сохранение результата и письма. Ошибки заполнения — 200 и `success: false`. '
       'Файлы: только разрешённые расширения и до лимита; результатов формы — не больше лимита в минуту и час (429).',
       [], ref('FormAddResult'), ('400', '404', '429'),
       request_body={'required': True, 'content': {'application/json': {'schema': ref('FormAddRequest')}}}))
add(op('captcha.get', FORMS, 'Капча', 'Код хранится в базе: покажите картинку `full_path`, а `sid` и введённый код передайте в form.add. Код одноразовый.',
       [], ref('Captcha'), ('429',)))

add(op('hl.list', HL, 'Записи highload-блока',
       'Записи справочника из белого списка: только открытые поля и активные записи. Список фильтруется по XML_ID значения, «да/нет» — `true`/`false`, даты — ISO.',
       [param('hl', STR, 'Имя (NAME) highload-блока.', True), P('select'), P('filter'), P('order'), P('page'),
        param('limit', {'type': 'integer', 'minimum': 1, 'maximum': 100, 'default': 20}, 'Записей на страницу.')],
       ref('HlList'), ('400',)))
add(op('hl.get', HL, 'Одна запись highload-блока', 'Запись по `id` или `xml_id`; выключенная — 404.',
       [param('hl', STR, 'Имя (NAME) highload-блока.', True), param('id', INT, 'ID записи.'), param('xml_id', STR, 'UF_XML_ID записи.'),
        P('select')], ref('HlItem'), ('400', '404')))

add(op('sitemap.index', MAP, 'Какие карты сайта есть', 'Одна карта на инфоблок из белого списка и `static`; `pages` — страниц по `page_size` адресов.',
       [], ref('SitemapIndex'), ()))
add(op('sitemap.get', MAP, 'Адреса карты сайта',
       'Страница списка инфоблока, разделы, элементы по порядку; исключённые флагами и настройками не отдаются. Адреса относительные.',
       [param('name', STR, 'Код инфоблока или `static` (`name` из sitemap.index).', True), P('page'),
        param('images', {'type': 'string', 'enum': ['Y']}, '`Y` — добавить ссылки на картинки (`images`).')],
       ref('SitemapPage'), ('400', '404')))


def build():
    return {
        'openapi': '3.0.3',
        'info': {
            'title': 'REST API (custom/rest, префикс ' + PREFIX + ')',
            'version': '1.0.0',
            'description': (
                'API для фронтенда: контент инфоблоков, поиск, веб-формы, справочники highload-блоков и карта сайта. '
                'Работает через входящий вебхук Битрикса, у которого только право `' + PREFIX + '`; секрет вебхука в адресе, поэтому запросы нужно делать '
                'с сервера, а не из браузера. Контент только читается; единственная запись — отправка веб-формы.\n\n'
                'Все методы доступны и как `GET` с параметрами в строке запроса (массивы — `select[]=a&select[]=b`, '
                'объекты — `filter[CODE]=x`), и как `POST` с JSON-телом. Символ `=` внутри ключа (`filter[>=…]`) кодируйте как `%3D`. '
                'Ответ успеха — `{"result": …, "time": …}`, ошибка — `{"error", "error_description"}` с HTTP-статусом.'),
        },
        'servers': [{
            'url': 'https://{host}/rest/{userId}/{secret}',
            'variables': {'host': {'default': 'site.ru'}, 'userId': {'default': '2'}, 'secret': {'default': 'SECRET'}},
        }],
        'tags': [{'name': name} for name in (IBLOCKS, SEARCH, FORMS, HL, MAP)],
        'paths': dict(sorted(PATHS.items())),
        'components': {'parameters': PARAMETERS, 'schemas': SCHEMAS, 'responses': RESPONSES},
    }


def check(spec):
    problems = []
    seen = set()

    def walk(node, where):
        if isinstance(node, dict):
            target = node.get('$ref')
            if target:
                parts = target.lstrip('#/').split('/')
                cursor = spec
                for part in parts:
                    cursor = cursor.get(part) if isinstance(cursor, dict) else None
                if cursor is None:
                    problems.append(f'{where}: ссылка {target} не найдена')
            for key, value in node.items():
                walk(value, f'{where}/{key}')
        elif isinstance(node, list):
            for i, value in enumerate(node):
                walk(value, f'{where}[{i}]')

    walk(spec, '')
    for path, item in spec['paths'].items():
        for verb, operation in item.items():
            if operation['operationId'] in seen:
                problems.append(f'{path}: operationId повторяется')
            seen.add(operation['operationId'])
            if not operation.get('summary') or not operation.get('description'):
                problems.append(f'{path}: нет summary или description')
            names = [p.get('name') for p in operation.get('parameters', []) if 'name' in p]
            if len(names) != len(set(names)):
                problems.append(f'{path}: повторяются параметры {names}')
    return problems


if __name__ == '__main__':
    spec = build()
    problems = check(spec)
    for problem in problems:
        print('ОШИБКА:', problem)
    if problems:
        sys.exit(1)

    text = json.dumps(spec, ensure_ascii=False, indent=2) + '\n'
    if '--check' in sys.argv:
        current = open(OUT, encoding='utf-8').read() if os.path.exists(OUT) else ''
        if current != text:
            print('local/docs/openapi.json устарел: запустите python3 tools/openapi.py')
            sys.exit(1)
        print('openapi.json актуален:', len(spec['paths']), 'методов')
    else:
        with open(OUT, 'w', encoding='utf-8') as fh:
            fh.write(text)
        print('Записан', os.path.relpath(OUT, ROOT), '—', len(spec['paths']), 'методов')
