<?php
// Тестовые данные для пакета custom/rest. Только для локального стенда, на боевом сайте не запускать.
// Запуск внутри Битрикса (тем же PHP и от того же пользователя, что и сайт), например в Docker-стенде:
//   docker compose exec -T -u www-data php php < local/packages/custom-rest/tests/fixtures/seed.php
// Корень сайта — переменная BITRIX_ROOT (по умолчанию /var/www/html).
// Пересоздаёт тип инфоблоков custom_rest_test целиком, поэтому запускать можно повторно:
//   testclubs    — инфоблок 1.0: разделы-города (в т.ч. неактивные), клубы со свойствами всех типов (включая
//                  справочник AMENITIES → TestAmenities), SEO-шаблоны,
//                  шаблоны URL, свойства в умном фильтре (по ним API фильтрует и считает значения),
//                  «Удобства» участвуют в поиске (метод search);
//                  черновик, будущая и истёкшая публикации и клуб в скрытом разделе — API их отдавать не должен
//   testtrainers — инфоблок 2.0 (свойства в отдельных таблицах)
//   testleads    — «заявки» с телефоном; НЕ в белом списке API — проверка запрета
//   веб-формы    — TEST_FEEDBACK (поля всех типов, валидатор, служебное поле и выключенный вопрос — API их не
//                  отдаёт), TEST_CALLBACK (с капчей) и TEST_SECRET (не в белом списке FormApi —
//                  проверка запрета). У форм есть статус по умолчанию и почтовый шаблон — без статуса
//                  результат не сохранится, письма на стенде ловит Mailpit
//   highload-блоки — TestAmenities (справочник удобств: поля всех основных типов, выключенная запись и служебное
//                  поле — API их не отдаёт) и TestPartners (не в белом списке HlApi — проверка запрета)
// Чтобы API отдавал тестовые инфоблоки, формы и highload-блок, они должны быть в local/php_interface/config/custom-rest.local.php.

$_SERVER['DOCUMENT_ROOT'] = getenv('BITRIX_ROOT') ?: '/var/www/html';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('STOP_STATISTICS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

Loader::includeModule('iblock');

function fail(string $what, $obj): void
{
    fwrite(STDERR, "ОШИБКА: {$what}: " . strip_tags($obj->LAST_ERROR ?? '') . "\n");
    exit(1);
}

const TYPE = 'custom_rest_test';

// --- чистим прошлый прогон
$DB->StartTransaction();
if (CIBlockType::GetByID(TYPE)->Fetch()) {
    CIBlockType::Delete(TYPE);
}
$DB->Commit();

$type = new CIBlockType();
$type->Add([
    'ID' => TYPE, 'SECTIONS' => 'Y', 'SORT' => 900,
    'LANG' => ['ru' => ['NAME' => 'Тест REST API'], 'en' => ['NAME' => 'REST API test']],
]) || fail('тип инфоблоков', $type);

function addIblock(string $code, string $name, int $version, array $extra = []): int
{
    $ib = new CIBlock();
    $id = $ib->Add($extra + [
        'IBLOCK_TYPE_ID' => TYPE, 'LID' => ['s1'], 'CODE' => $code, 'API_CODE' => $code,
        'NAME' => $name, 'VERSION' => $version, 'ACTIVE' => 'Y', 'GROUP_ID' => ['2' => 'R'],
    ]);
    $id || fail("инфоблок {$code}", $ib);
    return (int) $id;
}

function addProp(int $iblockId, array $fields): int
{
    $p = new CIBlockProperty();
    $id = $p->Add($fields + ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
    $id || fail("свойство {$fields['CODE']}", $p);
    return (int) $id;
}

function addSection(int $iblockId, string $name, string $code, ?int $parent = null, string $active = 'Y', int $withPicture = 0): int
{
    $s = new CIBlockSection();
    $fields = [
        'IBLOCK_ID' => $iblockId, 'NAME' => $name, 'CODE' => $code, 'ACTIVE' => $active,
        'IBLOCK_SECTION_ID' => $parent, 'DESCRIPTION' => "Клубы: {$name}", 'SORT' => 100,
    ];
    if ($withPicture) {
        $fields['PICTURE'] = makeImage("section-{$code}", $withPicture);
    }
    $id = $s->Add($fields);
    $id || fail("раздел {$code}", $s);
    return (int) $id;
}

function addElement(int $iblockId, array $fields, array $props = []): int
{
    $e = new CIBlockElement();
    $id = $e->Add($fields + ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'PROPERTY_VALUES' => $props]);
    $id || fail("элемент {$fields['NAME']}", $e);
    return (int) $id;
}

/** Картинка-заглушка с подписью: массив для CFile. */
function makeImage(string $name, int $seed): array
{
    $img = imagecreatetruecolor(640, 400);
    $bg = imagecolorallocate($img, (40 * $seed) % 200 + 30, (70 * $seed) % 200 + 30, (110 * $seed) % 200 + 30);
    imagefill($img, 0, 0, $bg);
    imagestring($img, 5, 20, 185, $name, imagecolorallocate($img, 255, 255, 255));
    $path = sys_get_temp_dir() . "/{$name}.png";
    imagepng($img, $path);
    imagedestroy($img);

    return CFile::MakeFileArray($path) + ['MODULE_ID' => 'iblock'];
}

// ===== testtrainers (2.0) =====
$trainersId = addIblock('testtrainers', 'Тренеры (тест)', 2, [
    'SECTION_PROPERTY' => 'Y', // без привязки свойств к разделам флаг «умного фильтра» не работает
    'LIST_PAGE_URL' => '#SITE_DIR#/trainers/',
    'SECTION_PAGE_URL' => '#SITE_DIR#/trainers/#SECTION_CODE_PATH#/',
    'DETAIL_PAGE_URL' => '#SITE_DIR#/trainers/#ELEMENT_CODE#/',
]);
addProp($trainersId, [
    'CODE' => 'SPECIALIZATION', 'NAME' => 'Специализация', 'PROPERTY_TYPE' => 'L',
    'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'K',
    'VALUES' => [
        ['VALUE' => 'Силовые', 'XML_ID' => 'strength', 'SORT' => 10],
        ['VALUE' => 'Йога', 'XML_ID' => 'yoga', 'SORT' => 20],
        ['VALUE' => 'Плавание', 'XML_ID' => 'swim', 'SORT' => 30],
    ],
]);
addProp($trainersId, [
    'CODE' => 'EXPERIENCE', 'NAME' => 'Стаж, лет', 'PROPERTY_TYPE' => 'N', 'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'B',
]);
addProp($trainersId, ['CODE' => 'PHOTO', 'NAME' => 'Фото', 'PROPERTY_TYPE' => 'F', 'FILE_TYPE' => 'jpg, png']);
addProp($trainersId, ['CODE' => 'CERTIFICATES', 'NAME' => 'Сертификаты', 'PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'Y']);

$enum = [];
$rs = CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $trainersId]);
while ($row = $rs->Fetch()) {
    $enum[$row['XML_ID']] = (int) $row['ID'];
}

$trainers = [];
foreach ([
    ['Анна Смирнова', 'anna-smirnova', 'yoga', 7, ['RYT-200', 'Йога-терапия']],
    ['Игорь Волков', 'igor-volkov', 'strength', 12, ['FPA', 'CrossFit L2']],
    ['Мария Ким', 'maria-kim', 'swim', 4, []],
] as $i => [$name, $code, $spec, $exp, $certs]) {
    $trainers[$code] = addElement($trainersId, [
        'NAME' => $name, 'CODE' => $code, 'SORT' => ($i + 1) * 10,
        'PREVIEW_TEXT' => "Тренер: {$name}",
    ], [
        'SPECIALIZATION' => $enum[$spec],
        'EXPERIENCE' => $exp,
        'PHOTO' => makeImage("trainer-{$code}", $i + 5),
        'CERTIFICATES' => $certs ?: false,
    ]);
}

// ===== testclubs (1.0) =====
// Шаблоны URL: из них API строит ссылки в хлебных крошках
$clubsId = addIblock('testclubs', 'Клубы (тест)', 1, [
    'SECTION_PROPERTY' => 'Y',
    'DESCRIPTION' => 'Сеть фитнес-клубов в трёх городах.', 'DESCRIPTION_TYPE' => 'text',
    'LIST_PAGE_URL' => '#SITE_DIR#/clubs/',
    'SECTION_PAGE_URL' => '#SITE_DIR#/clubs/#SECTION_CODE_PATH#/',
    'DETAIL_PAGE_URL' => '#SITE_DIR#/clubs/#SECTION_CODE_PATH#/#ELEMENT_CODE#/',
]);

// SEO-шаблоны: проверяем, что API отдаёт вычисленные значения
(new \Bitrix\Iblock\InheritedProperty\IblockTemplates($clubsId))->set([
    'ELEMENT_META_TITLE' => 'Фитнес-клуб {=this.Name} — Demo',
    'ELEMENT_META_DESCRIPTION' => 'Фитнес-клуб {=this.Name}: расписание, тренеры, цены.',
    'ELEMENT_PREVIEW_PICTURE_FILE_ALT' => 'Фото клуба {=this.Name}',
    'SECTION_META_TITLE' => 'Фитнес-клубы: {=this.Name}',
    'SECTION_META_DESCRIPTION' => 'Список клубов сети: {=this.Name}.',
    'SECTION_PAGE_TITLE' => 'Все клубы сети',
]);

addProp($clubsId, [
    'CODE' => 'FORMAT', 'NAME' => 'Формат', 'PROPERTY_TYPE' => 'L',
    'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'F', 'DISPLAY_EXPANDED' => 'Y',
    'VALUES' => [
        ['VALUE' => 'Премиум', 'XML_ID' => 'premium', 'SORT' => 10],
        ['VALUE' => 'Стандарт', 'XML_ID' => 'standard', 'SORT' => 20],
    ],
]);
addProp($clubsId, [
    'CODE' => 'AREA', 'NAME' => 'Площадь, м²', 'PROPERTY_TYPE' => 'N',
    'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'A', 'FILTER_HINT' => 'Общая площадь клуба',
]);
addProp($clubsId, [
    'CODE' => 'FEATURES', 'NAME' => 'Удобства', 'PROPERTY_TYPE' => 'S', 'MULTIPLE' => 'Y',
    'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'F',
    'SEARCHABLE' => 'Y', // значения попадают в поисковый индекс: «бассейн» найдёт клубы с бассейном
]);
addProp($clubsId, ['CODE' => 'SCHEDULE', 'NAME' => 'Режим работы', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'HTML']);
addProp($clubsId, ['CODE' => 'PHOTOS', 'NAME' => 'Фотогалерея', 'PROPERTY_TYPE' => 'F', 'MULTIPLE' => 'Y', 'FILE_TYPE' => 'jpg, png']);
addProp($clubsId, [
    'CODE' => 'TRAINERS', 'NAME' => 'Тренеры', 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'Y', 'LINK_IBLOCK_ID' => $trainersId,
    'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'P',
]);
addProp($clubsId, ['CODE' => 'INTERNAL_NOTE', 'NAME' => 'Служебная заметка', 'PROPERTY_TYPE' => 'S']);
// Флаги исключения из карты сайта (методы sitemap.*): у элемента — любое значение свойства, у раздела — поле «Да/нет».
addProp($clubsId, ['CODE' => 'NO_INDEX', 'NAME' => 'Не включать в карту сайта', 'PROPERTY_TYPE' => 'S']);
(new CUserTypeEntity())->Add([
    'ENTITY_ID' => 'IBLOCK_' . $clubsId . '_SECTION', 'FIELD_NAME' => 'UF_NO_INDEX', 'USER_TYPE_ID' => 'boolean',
    'MULTIPLE' => 'N', 'MANDATORY' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N',
    'EDIT_FORM_LABEL' => ['ru' => 'Не включать в карту сайта'], 'LIST_COLUMN_LABEL' => ['ru' => 'Не в карте сайта'],
]) || fail('поле UF_NO_INDEX', (object) ['LAST_ERROR' => '']);

$format = [];
$rs = CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $clubsId]);
while ($row = $rs->Fetch()) {
    $format[$row['XML_ID']] = (int) $row['ID'];
}

$moscow = addSection($clubsId, 'Москва', 'moscow', null, 'Y', 1);
$moscowCenter = addSection($clubsId, 'Центр', 'moscow-center', $moscow, 'Y', 2);
(new CIBlockSection())->Update($moscowCenter, ['UF_NO_INDEX' => 1]); // «Центр» с клубами не попадёт в карту сайта
addSection($clubsId, 'Север (закрыт)', 'moscow-north', $moscow, 'N');
$spb = addSection($clubsId, 'Санкт-Петербург', 'spb', null, 'Y', 3);
$kazan = addSection($clubsId, 'Казань (скрыт)', 'kazan', null, 'N');
$kazanCenter = addSection($clubsId, 'Казань-Центр', 'kazan-center', $kazan, 'Y');

$club = static function (string $name, string $code, int $section, int $n, array $extra = [], array $props = []) use ($clubsId, $format, $trainers) {
    return addElement($clubsId, $extra + [
        'NAME' => $name, 'CODE' => $code, 'IBLOCK_SECTION_ID' => $section, 'SORT' => $n * 10,
        'PREVIEW_TEXT' => "Клуб «{$name}» — коротко.",
        'DETAIL_TEXT' => "<p>Клуб «<b>{$name}</b>» — подробное описание.</p>", 'DETAIL_TEXT_TYPE' => 'html',
        'PREVIEW_PICTURE' => makeImage("club-{$code}-preview", $n),
        'DETAIL_PICTURE' => makeImage("club-{$code}-detail", $n + 1),
    ], $props + [
        'FORMAT' => $format[$n % 2 ? 'premium' : 'standard'],
        'AREA' => 800 + $n * 150.5,
        'FEATURES' => ['Бассейн', 'Сауна', 'Парковка'],
        'SCHEDULE' => ['VALUE' => ['TEXT' => "<p>Пн–Пт: 07:00–23:00<br>Сб–Вс: 09:00–21:00</p>", 'TYPE' => 'HTML']],
        'PHOTOS' => [
            'n0' => ['VALUE' => makeImage("club-{$code}-gallery-1", $n + 2)],
            'n1' => ['VALUE' => makeImage("club-{$code}-gallery-2", $n + 3)],
        ],
        'TRAINERS' => array_values($trainers),
        'INTERNAL_NOTE' => 'Арендатор должен за 2 месяца',
    ]);
};

$ids = [];
$ids['arbat'] = $club('Арбат', 'arbat', $moscowCenter, 1);
$ids['tverskaya'] = $club('Тверская', 'tverskaya', $moscowCenter, 2, [], ['FEATURES' => false, 'PHOTOS' => false, 'TRAINERS' => [$trainers['anna-smirnova']]]);
$ids['nevsky'] = $club('Невский', 'nevsky', $spb, 3);
$ids['vasilievsky'] = $club('Васильевский', 'vasilievsky', $spb, 4, [], ['NO_INDEX' => 'Y']); // не попадёт в карту сайта
// Не должны попадать в API:
$ids['draft'] = $club('Черновик клуба', 'draft', $spb, 5, ['ACTIVE' => 'N']);
$ids['future'] = $club('Скоро открытие', 'future', $spb, 6, ['ACTIVE_FROM' => DateTime::createFromTimestamp(strtotime('+30 days'))]);
$ids['closed'] = $club('Закрытый клуб', 'closed', $spb, 7, ['ACTIVE_TO' => DateTime::createFromTimestamp(strtotime('-1 day'))]);
$ids['kazan'] = $club('Казань Арена', 'kazan-arena', $kazanCenter, 8);

// ===== testleads: не в белом списке =====
$leadsId = addIblock('testleads', 'Заявки (тест, закрыто)', 1);
addProp($leadsId, ['CODE' => 'PHONE', 'NAME' => 'Телефон', 'PROPERTY_TYPE' => 'S']);
addElement($leadsId, ['NAME' => 'Иван, пробное занятие'], ['PHONE' => '+7 900 000-00-00']);

// ===== веб-формы =====
Loader::includeModule('form');

foreach (['TEST_FEEDBACK', 'TEST_CALLBACK', 'TEST_SECRET'] as $formSid) {
    if ($oldForm = CForm::GetBySID($formSid)->Fetch()) {
        CForm::Delete($oldForm['ID'], 'N');
    }
}

function formFields(array $fields): array
{
    return $fields + [
        'C_SORT' => 100, 'USE_CAPTCHA' => 'N', 'DESCRIPTION_TYPE' => 'text',
        'arSITE' => ['s1'], 'arMENU' => ['ru' => $fields['NAME'], 'en' => $fields['NAME']],
        'arGROUP' => ['2' => 10], // все посетители могут заполнять
    ];
}

function addForm(array $fields): int
{
    global $strError;
    $id = CForm::Set(formFields($fields), false, 'N');
    $id || fail('форма ' . $fields['SID'], (object) ['LAST_ERROR' => $strError]);

    // Без статуса по умолчанию CFormResult::Add не сохранит результат.
    CFormStatus::Set([
        'FORM_ID' => $id, 'TITLE' => 'Новая', 'C_SORT' => 100, 'ACTIVE' => 'Y', 'DEFAULT_VALUE' => 'Y',
        'arPERMISSION_VIEW' => [0], 'arPERMISSION_MOVE' => [0], 'arPERMISSION_EDIT' => [], 'arPERMISSION_DELETE' => [],
    ], false, 'N') || fail('статус формы ' . $fields['SID'], (object) ['LAST_ERROR' => $strError]);

    return (int) $id;
}

/** Почтовый шаблон — после вопросов: Битрикс собирает текст письма из них. */
function addFormMail(int $formId, array $fields): void
{
    global $strError;
    $templates = CForm::SetMailTemplate($formId, 'Y');
    CForm::Set(formFields($fields) + ['arMAIL_TEMPLATE' => $templates], $formId, 'N')
        || fail('почтовый шаблон формы ' . $fields['SID'], (object) ['LAST_ERROR' => $strError]);
}

function addQuestion(int $formId, array $fields, array $answers): int
{
    global $strError;
    $sort = 0;
    $id = CFormField::Set($fields + [
        'FORM_ID' => $formId, 'ACTIVE' => 'Y', 'ADDITIONAL' => 'N', 'REQUIRED' => 'N', 'TITLE_TYPE' => 'text',
        'C_SORT' => 100,
        'arANSWER' => array_map(static function (array $answer) use (&$sort): array {
            return $answer + ['MESSAGE' => ' ', 'C_SORT' => $sort += 10, 'ACTIVE' => 'Y', 'FIELD_PARAM' => ''];
        }, $answers),
    ], false, 'N', 'N');
    $id || fail('вопрос ' . $fields['SID'], (object) ['LAST_ERROR' => $strError]);
    return (int) $id;
}

$feedbackFields = [
    'SID' => 'TEST_FEEDBACK', 'NAME' => 'Записаться на пробное', 'BUTTON' => 'Записаться',
    'DESCRIPTION' => 'Оставьте контакты — администратор клуба перезвонит в течение часа.',
];
$feedbackId = addForm($feedbackFields);
$nameId = addQuestion($feedbackId, ['SID' => 'NAME', 'TITLE' => 'Ваше имя', 'REQUIRED' => 'Y', 'C_SORT' => 10],
    [['FIELD_TYPE' => 'text']]);
CFormValidator::Set($feedbackId, $nameId, 'text_len', ['LENGTH_FROM' => 2, 'LENGTH_TO' => 50]);
addQuestion($feedbackId, ['SID' => 'PHONE', 'TITLE' => 'Телефон', 'REQUIRED' => 'Y', 'C_SORT' => 20,
    'COMMENTS' => 'Например, +7 900 000-00-00'], [['FIELD_TYPE' => 'text']]);
addQuestion($feedbackId, ['SID' => 'EMAIL', 'TITLE' => 'E-mail', 'C_SORT' => 30], [['FIELD_TYPE' => 'email']]);
addQuestion($feedbackId, ['SID' => 'CLUB', 'TITLE' => 'Клуб', 'REQUIRED' => 'Y', 'C_SORT' => 40], [
    ['FIELD_TYPE' => 'dropdown', 'MESSAGE' => 'Арбат', 'FIELD_PARAM' => 'selected'],
    ['FIELD_TYPE' => 'dropdown', 'MESSAGE' => 'Невский'],
    ['FIELD_TYPE' => 'dropdown', 'MESSAGE' => 'Васильевский'],
]);
addQuestion($feedbackId, ['SID' => 'TOPIC', 'TITLE' => 'Что интересует', 'C_SORT' => 50], [
    ['FIELD_TYPE' => 'radio', 'MESSAGE' => 'Пробное занятие', 'FIELD_PARAM' => 'checked'],
    ['FIELD_TYPE' => 'radio', 'MESSAGE' => 'Абонемент'],
    ['FIELD_TYPE' => 'radio', 'MESSAGE' => 'Персональные тренировки'],
]);
addQuestion($feedbackId, ['SID' => 'TIME', 'TITLE' => 'Удобное время', 'C_SORT' => 60], [
    ['FIELD_TYPE' => 'checkbox', 'MESSAGE' => 'Утро'],
    ['FIELD_TYPE' => 'checkbox', 'MESSAGE' => 'День'],
    ['FIELD_TYPE' => 'checkbox', 'MESSAGE' => 'Вечер'],
]);
addQuestion($feedbackId, ['SID' => 'DATE', 'TITLE' => 'Желаемая дата', 'C_SORT' => 70], [['FIELD_TYPE' => 'date']]);
addQuestion($feedbackId, ['SID' => 'COMMENT', 'TITLE' => 'Комментарий', 'C_SORT' => 80],
    [['FIELD_TYPE' => 'textarea', 'FIELD_WIDTH' => 40, 'FIELD_HEIGHT' => 5]]);
addQuestion($feedbackId, ['SID' => 'CERTIFICATE', 'TITLE' => 'Медицинская справка', 'C_SORT' => 85],
    [['FIELD_TYPE' => 'file']]);
addQuestion($feedbackId, ['SID' => 'CONSENT', 'TITLE' => 'Согласие', 'REQUIRED' => 'Y', 'C_SORT' => 90],
    [['FIELD_TYPE' => 'checkbox', 'MESSAGE' => 'Согласен на обработку персональных данных']]);
// Не должны попадать в API:
addQuestion($feedbackId, ['SID' => 'OLD_QUESTION', 'TITLE' => 'Выключенный вопрос', 'ACTIVE' => 'N', 'C_SORT' => 100],
    [['FIELD_TYPE' => 'text']]);
CFormField::Set([
    'FORM_ID' => $feedbackId, 'SID' => 'UTM_SOURCE', 'TITLE' => 'Источник (служебное поле)', 'ACTIVE' => 'Y',
    'ADDITIONAL' => 'Y', 'FIELD_TYPE' => 'text', 'C_SORT' => 110,
], false, 'N', 'N') || fail('служебное поле', (object) ['LAST_ERROR' => $GLOBALS['strError']]);

addFormMail($feedbackId, $feedbackFields);

$callbackFields = ['SID' => 'TEST_CALLBACK', 'NAME' => 'Обратный звонок', 'BUTTON' => 'Перезвоните мне', 'USE_CAPTCHA' => 'Y'];
$callbackId = addForm($callbackFields);
addQuestion($callbackId, ['SID' => 'PHONE', 'TITLE' => 'Телефон', 'REQUIRED' => 'Y'], [['FIELD_TYPE' => 'text']]);
addFormMail($callbackId, $callbackFields);

$secretId = addForm(['SID' => 'TEST_SECRET', 'NAME' => 'Внутренняя анкета (тест, закрыто)', 'BUTTON' => 'Отправить']);
addQuestion($secretId, ['SID' => 'SALARY', 'TITLE' => 'Зарплата'], [['FIELD_TYPE' => 'text']]);

// ===== highload-блоки =====
Loader::includeModule('highloadblock');

foreach (['TestAmenities', 'TestPartners'] as $hlName) {
    if ($oldHl = \Bitrix\Highloadblock\HighloadBlockTable::getList(['filter' => ['=NAME' => $hlName]])->fetch()) {
        \Bitrix\Highloadblock\HighloadBlockTable::delete($oldHl['ID']); // вместе с таблицей и полями
    }
}

function addHl(string $name, string $table, string $title): array
{
    $result = \Bitrix\Highloadblock\HighloadBlockTable::add(['NAME' => $name, 'TABLE_NAME' => $table]);
    $result->isSuccess() || fail("highload-блок {$name}", (object) ['LAST_ERROR' => implode('; ', $result->getErrorMessages())]);
    $id = (int) $result->getId();
    \Bitrix\Highloadblock\HighloadBlockLangTable::add(['ID' => $id, 'LID' => 'ru', 'NAME' => $title]);

    return ['id' => $id, 'entity' => 'HLBLOCK_' . $id];
}

function addUf(string $entityId, string $name, string $type, string $label, array $extra = []): int
{
    global $APPLICATION;
    $id = (new CUserTypeEntity())->Add($extra + [
        'ENTITY_ID' => $entityId, 'FIELD_NAME' => $name, 'USER_TYPE_ID' => $type, 'MULTIPLE' => 'N',
        'MANDATORY' => 'N', 'SHOW_FILTER' => 'N', 'IS_SEARCHABLE' => 'N',
        'EDIT_FORM_LABEL' => ['ru' => $label], 'LIST_COLUMN_LABEL' => ['ru' => $label],
    ]);
    $id || fail("поле {$name}", (object) ['LAST_ERROR' => ($e = $APPLICATION->GetException()) ? $e->GetString() : '']);
    return (int) $id;
}

$amenities = addHl('TestAmenities', 'test_amenities', 'Удобства клубов (тест)');
addUf($amenities['entity'], 'UF_NAME', 'string', 'Название', ['MANDATORY' => 'Y']);
addUf($amenities['entity'], 'UF_XML_ID', 'string', 'Внешний код');
addUf($amenities['entity'], 'UF_SORT', 'integer', 'Сортировка');
addUf($amenities['entity'], 'UF_DESCRIPTION', 'string', 'Описание', ['SETTINGS' => ['ROWS' => 3]]);
addUf($amenities['entity'], 'UF_ICON', 'file', 'Иконка');
$typeFieldId = addUf($amenities['entity'], 'UF_TYPE', 'enumeration', 'Тип');
(new CUserFieldEnum())->SetEnumValues($typeFieldId, [
    'n0' => ['VALUE' => 'Вода', 'XML_ID' => 'water', 'SORT' => 10],
    'n1' => ['VALUE' => 'Восстановление', 'XML_ID' => 'recovery', 'SORT' => 20],
    'n2' => ['VALUE' => 'Сервис', 'XML_ID' => 'service', 'SORT' => 30],
]);
addUf($amenities['entity'], 'UF_PAID', 'boolean', 'Платно');
addUf($amenities['entity'], 'UF_ACTIVE', 'boolean', 'Активно');
addUf($amenities['entity'], 'UF_SINCE', 'date', 'Доступно с');
addUf($amenities['entity'], 'UF_TAGS', 'string', 'Метки', ['MULTIPLE' => 'Y']);
addUf($amenities['entity'], 'UF_INTERNAL', 'string', 'Служебная заметка'); // нет в белом списке API

$typeIds = [];
$rsEnum = CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $typeFieldId]);
while ($enum = $rsEnum->Fetch()) {
    $typeIds[$enum['XML_ID']] = (int) $enum['ID'];
}

// force: сущность уже собрана при добавлении множественного поля (UF_TAGS)
// и без пересборки не знает о полях, добавленных после, — UF_INTERNAL не сохранился бы.
$amenityClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($amenities['id'], true)->getDataClass();
$amenityIds = [];
foreach ([
    ['pool', 'Бассейн', 10, 'water', false, true, ['25 м', '6 дорожек'], 'Подогрев до 28 °C'],
    ['sauna', 'Сауна', 20, 'recovery', true, true, ['Финская'], ''],
    ['parking', 'Парковка', 30, 'service', false, true, [], 'Бесплатно для членов клуба'],
    ['kids', 'Детская комната', 40, 'service', true, false, [], 'Откроется позже'], // выключена — API не отдаёт
] as $n => [$xmlId, $name, $sort, $type, $paid, $active, $tags, $description]) {
    $result = $amenityClass::add([
        'UF_NAME' => $name, 'UF_XML_ID' => $xmlId, 'UF_SORT' => $sort, 'UF_DESCRIPTION' => $description,
        'UF_TYPE' => $typeIds[$type], 'UF_PAID' => $paid ? 1 : 0, 'UF_ACTIVE' => $active ? 1 : 0,
        'UF_SINCE' => new \Bitrix\Main\Type\Date('2026-0' . ($n + 1) . '-01', 'Y-m-d'),
        'UF_TAGS' => $tags, 'UF_ICON' => makeImage("amenity-{$xmlId}", $n + 7),
        'UF_INTERNAL' => 'Договор с подрядчиком №' . (100 + $n),
    ]);
    $result->isSuccess() || fail("запись {$name}", (object) ['LAST_ERROR' => implode('; ', $result->getErrorMessages())]);
    $amenityIds[$xmlId] = (int) $result->getId();
}

// Свойство-справочник у клубов: значение — UF_XML_ID записи TestAmenities. Добавляется здесь,
// потому что ему нужна таблица highload-блока. Выключенная «kids» у Невского — API отдаёт xml_id как есть.
addProp($clubsId, [
    'CODE' => 'AMENITIES', 'NAME' => 'Удобства (справочник)', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'directory',
    'MULTIPLE' => 'Y', 'SMART_FILTER' => 'Y', 'DISPLAY_TYPE' => 'F',
    'USER_TYPE_SETTINGS' => ['TABLE_NAME' => 'test_amenities'],
]);
foreach ([
    'arbat' => ['pool', 'sauna', 'parking'],
    'tverskaya' => ['parking'],
    'nevsky' => ['pool', 'kids'],
    'vasilievsky' => ['sauna'],
] as $clubCode => $amenityCodes) {
    CIBlockElement::SetPropertyValuesEx($ids[$clubCode], $clubsId, ['AMENITIES' => $amenityCodes]);
}

$partners = addHl('TestPartners', 'test_partners', 'Партнёры (тест, закрыто)');
addUf($partners['entity'], 'UF_NAME', 'string', 'Название');
\Bitrix\Highloadblock\HighloadBlockTable::compileEntity($partners['id'])->getDataClass()::add(['UF_NAME' => 'ООО «Подрядчик»']);

echo json_encode([
    'hlblocks' => ['TestAmenities' => $amenities['id'], 'TestPartners' => $partners['id'], 'amenities' => $amenityIds],
    'forms' => ['TEST_FEEDBACK' => $feedbackId, 'TEST_CALLBACK' => $callbackId, 'TEST_SECRET' => $secretId],
    'iblocks' => ['testclubs' => $clubsId, 'testtrainers' => $trainersId, 'testleads' => $leadsId],
    'sections' => compact('moscow', 'moscowCenter', 'spb', 'kazan', 'kazanCenter'),
    'clubs' => $ids,
    'trainers' => $trainers,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
