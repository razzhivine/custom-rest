<?php

namespace Custom\Rest\Api;

use Custom\Rest\Config;
use Custom\Rest\Support\Content;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Rest\RestException;

/**
 * REST-методы веб-форм (модуль form): получить форму, отправить её,
 * получить капчу. Общая обработка ошибок и ответы 400/404 — из
 * AbstractApi, регистрация — в Registry.
 */
class FormApi extends AbstractApi
{
    /**
     * Ограничения по умолчанию; меняются ключом form_limits в настройках API.
     *   max_file_size — размер файла в байтах;
     *   allowed_extensions — расширения файлов, которые принимаются;
     *   per_minute / per_hour — сколько результатов одной формы можно сохранить
     *     за минуту / час (считается по базе, на всю форму, не по посетителю:
     *     вебхук вызывает ваш сервер, а не браузер посетителя);
     *   captcha_per_minute — сколько капч можно выдать за минуту.
     * 0 — без ограничения.
     */
    private const LIMITS = [
        'max_file_size' => 5 * 1024 * 1024,
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
        'per_minute' => 10,
        'per_hour' => 100,
        'captcha_per_minute' => 60,
    ];

    /**
     * Типы ответов, из которых выбирают: варианты одного вопроса
     * одного такого типа собираются в одно поле с options.
     */
    private const CHOICE_TYPES = ['radio', 'dropdown', 'checkbox', 'multiselect'];

    /**
     * Выбор нескольких вариантов: в имени поля для отправки значения
     * передаются массивом.
     */
    private const MULTIPLE_TYPES = ['checkbox', 'multiselect'];

    /**
     * @param array $params ['sid' => string] — символьный идентификатор формы
     *
     * Форма для вывода на сайте: заголовок, описание, кнопка и вопросы
     * с полями ввода. Служебные поля формы (не вопросы) и неактивные
     * вопросы и ответы не отдаются.
     *
     * У каждого поля ввода есть name — имя, под которым Битрикс принимает
     * значение при отправке (form_text_5, form_radio_TOPIC, …).
     */
    public static function getForm(array $params): array
    {
        return self::run(static function () use ($params): array {
            [$form, $questions, $answers] = self::loadForm($params);

            $validators = self::loadValidators((int) $form['ID']);

            $fields = [];
            foreach ($questions as $questionSid => $question) {
                $controls = self::buildControls((string) $questionSid, $answers[$questionSid] ?? []);
                if (empty($controls)) {
                    continue;
                }

                $fields[] = [
                    'sid' => (string) $questionSid,
                    'title' => [
                        'value' => (string) $question['TITLE'],
                        'type' => self::textType($question['TITLE_TYPE'] ?? ''),
                    ],
                    'required' => ($question['REQUIRED'] ?? 'N') === 'Y',
                    'comment' => (string) ($question['COMMENTS'] ?? ''),
                    'image' => Content::getFileData((int) ($question['IMAGE_ID'] ?? 0)),
                    'controls' => $controls,
                    'validators' => $validators[(int) $question['ID']] ?? [],
                ];
            }

            return [
                'id' => (int) $form['ID'],
                'sid' => (string) $form['SID'],
                'name' => (string) $form['NAME'],
                'description' => [
                    'value' => (string) ($form['DESCRIPTION'] ?? ''),
                    'type' => self::textType($form['DESCRIPTION_TYPE'] ?? ''),
                ],
                'button' => (string) ($form['BUTTON'] ?? ''),
                'use_captcha' => ($form['USE_CAPTCHA'] ?? 'N') === 'Y',
                'image' => Content::getFileData((int) ($form['IMAGE_ID'] ?? 0)),
                'fields' => $fields,
            ];
        });
    }

    /**
     * @param array $params ['sid' => string, 'values' => [name => значение],
     *                      'captcha_sid' => string, 'captcha_word' => string]
     *
     * Отправка формы — как штатным компонентом form.result.new: проверка
     * CForm::Check (обязательные поля, e-mail, даты, файлы, валидаторы,
     * капча), сохранение результата со статусом формы по умолчанию,
     * затем события и письма по почтовым шаблонам формы.
     *
     * values — значения по name полей из form.get: у текстовых —
     * строка, у выбора одного варианта — value варианта, у выбора нескольких —
     * массив value. Файл — ["имя.pdf", "<содержимое в base64>"]. Ключи,
     * которых нет в форме, игнорируются.
     *
     * Ошибки заполнения — не исключение, а ответ {success: false, errors:
     * [{field, message}]}: field — SID вопроса, 'captcha' или null (общая
     * ошибка). Успех — {success: true, result_id}. Код капчи одноразовый:
     * после любой отправки, удачной или нет, нужна новая капча.
     *
     * Защита от спама: файлы — только разрешённых расширений и не больше
     * max_file_size; результатов одной формы — не больше per_minute / per_hour
     * (иначе 429); скрытое поле-ловушка 'hp': если фронт показывает его
     * посетителю невидимо, а бот заполняет, форма отвечает успехом, но ничего
     * не сохраняет (result_id = 0).
     */
    public static function addResult(array $params): array
    {
        return self::run(static function () use ($params): array {
            [$form, $questions, $answers] = self::loadForm($params);
            $formId = (int) $form['ID'];

            // Бот, заполнивший скрытое поле: делаем вид, что приняли.
            if (self::paramString($params, 'hp') !== '') {
                return ['success' => true, 'result_id' => 0];
            }

            self::checkRate($formId);

            [$values, $fileErrors] = self::prepareValues($questions, $answers, self::paramArray($params, 'values', []));
            if (!empty($fileErrors)) {
                return ['success' => false, 'errors' => $fileErrors];
            }

            if (($form['USE_CAPTCHA'] ?? 'N') === 'Y') {
                $values['captcha_sid'] = self::paramString($params, 'captcha_sid');
                $values['captcha_word'] = self::paramString($params, 'captcha_word');
            }

            // Права не проверяются: доступ к форме даёт белый список FORMS.
            $errors = \CForm::Check($formId, $values, false, 'N', 'Y');
            if (!empty($errors)) {
                // Битрикс сжигает код капчи только при верном вводе. Здесь — при
                // любой неудаче, иначе один sid можно было бы подбирать запросами.
                if (isset($values['captcha_sid']) && $values['captcha_sid'] !== '') {
                    (new \CCaptcha())->Delete($values['captcha_sid']);
                }

                return ['success' => false, 'errors' => self::formatErrors((array) $errors)];
            }

            global $strError;
            $strError = '';
            $resultId = (int) \CFormResult::Add($formId, $values, 'N');
            if ($resultId <= 0) {
                throw new SystemException('Не удалось сохранить результат формы ' . $form['SID'] . ': ' . strip_tags((string) $strError));
            }

            \CFormCRM::onResultAdded($formId, $resultId);
            \CFormResult::SetEvent($resultId);
            \CFormResult::Mail($resultId);

            return ['success' => true, 'result_id' => $resultId];
        });
    }

    /**
     * Капча Битрикса для форм с включённой капчей. Код хранится в базе
     * (b_captcha), а не в сессии, поэтому подходит для фронта на другом
     * домене: покажите картинку, а sid и введённый код передайте
     * в form.add как captcha_sid и captcha_word. Код одноразовый:
     * сгорает после любой отправки формы.
     */
    public static function getCaptcha(array $params): array
    {
        return self::run(static function (): array {
            global $APPLICATION;

            $limit = (int) self::limit('captcha_per_minute');
            if ($limit > 0 && self::countSince('b_captcha', null, 60) >= $limit) {
                self::tooMany('Слишком много запросов капчи, повторите через минуту.');
            }

            $sid = (string) $APPLICATION->CaptchaGetCode();
            $src = '/bitrix/tools/captcha.php?captcha_sid=' . urlencode($sid);

            return [
                'sid' => $sid,
                'src' => $src,
                'full_path' => Content::getBaseUrl() . $src,
                'width' => 180,
                'height' => 40,
            ];
        });
    }

    private static function limit(string $name): mixed
    {
        return Config::get('form_limits')[$name] ?? self::LIMITS[$name];
    }

    private static function tooMany(string $message): never
    {
        throw new RestException($message, 'TOO_MANY_REQUESTS', \CRestServer::STATUS_TO_MANY_REQUESTS);
    }

    /**
     * Сохранённых результатов формы (или строк таблицы капч) за последние
     * $seconds секунд.
     */
    private static function countSince(string $table, ?int $formId, int $seconds): int
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        $where = 'DATE_CREATE >= ' . $helper->addSecondsToDateTime(-$seconds);
        if ($formId !== null) {
            $where = 'FORM_ID = ' . $formId . ' AND ' . $where;
        }

        return (int) $connection->queryScalar('SELECT COUNT(*) FROM ' . $helper->quote($table) . ' WHERE ' . $where);
    }

    private static function checkRate(int $formId): void
    {
        foreach (['per_minute' => 60, 'per_hour' => 3600] as $name => $seconds) {
            $limit = (int) self::limit($name);
            if ($limit > 0 && self::countSince('b_form_result', $formId, $seconds) >= $limit) {
                self::tooMany('Слишком много отправок формы, повторите позже.');
            }
        }
    }

    /**
     * Форма из белого списка по 'sid' с вопросами и ответами:
     * [форма, вопросы по SID, ответы по SID вопроса]. Только вопросы
     * (без служебных полей) и только активные вопросы и ответы.
     */
    private static function loadForm(array $params): array
    {
        if (!Loader::includeModule('form')) {
            throw new SystemException('Модуль веб-форм (form) не установлен');
        }

        $sid = self::paramString($params, 'sid');
        if ($sid === '') {
            self::badRequest("Параметр 'sid' обязателен");
        }

        // Веб-формы из настроек API (Config, ключ forms); остальные недоступны.
        if (!in_array($sid, Config::get('forms'), true)) {
            self::badRequest("Форма '{$sid}' недоступна");
        }

        $form = \CForm::GetBySID($sid)->Fetch();
        if (!$form) {
            self::notFound("Форма '{$sid}' не найдена");
        }

        \CForm::GetDataByID((int) $form['ID'], $form, $questions, $answers, $dropDown, $multiSelect, 'N', 'N');

        return [$form, $questions ?? [], $answers ?? []];
    }

    /**
     * Значения для CForm::Check / CFormResult::Add только по именам полей
     * этой формы, приведённые к ожидаемому виду: выбор одного — ID ответа,
     * выбор нескольких — массив ID, файл из base64 — массив файла.
     *
     * @return array{0: array, 1: array} [значения, ошибки файлов в формате formatErrors]
     */
    private static function prepareValues(array $questions, array $answers, array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($questions as $questionSid => $question) {
            foreach (self::buildControls((string) $questionSid, $answers[$questionSid] ?? []) as $control) {
                $name = $control['name'];
                if (!array_key_exists($name, $input)) {
                    continue;
                }

                $value = $input[$name];

                if ($control['multiple']) {
                    $values[$name] = array_values(array_filter(
                        array_map('intval', (array) $value),
                        static fn (int $id): bool => $id > 0
                    ));
                } elseif (isset($control['options'])) {
                    $values[$name] = is_scalar($value) ? (int) $value : 0;
                } elseif (in_array($control['type'], ['file', 'image'], true)) {
                    [$file, $fileError] = self::decodeFile($value);
                    if ($fileError !== null) {
                        $errors[] = ['field' => (string) $questionSid, 'message' => $fileError];
                    } elseif ($file !== []) {
                        $values[$name] = $file;
                    }
                } elseif (is_scalar($value)) {
                    $values[$name] = (string) $value;
                }
            }
        }

        return [$values, $errors];
    }

    /**
     * ["имя.pdf", "<base64>"] → [массив файла для модуля форм, текст ошибки].
     * Пустое значение — [[], null] (файл не прислан). Неверный формат, не
     * base64, чужое расширение или слишком большой размер — [null, ошибка].
     * base64 проверяется строго: иначе PHP молча превратит мусор в байты.
     * Расширение и размер проверяются до сохранения: лишний файл на диск
     * не попадает.
     *
     * @return array{0: ?array, 1: ?string}
     */
    private static function decodeFile(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [[], null];
        }

        $format = 'Файл не распознан: нужен ["имя файла", "содержимое в base64"].';
        if (!is_array($value) || count($value) !== 2) {
            return [null, $format];
        }

        [$fileName, $content] = array_values($value);
        if (!is_string($fileName) || !is_string($content) || ($bytes = base64_decode($content, true)) === false) {
            return [null, $format];
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', (array) self::limit('allowed_extensions'));
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            return [null, 'Файлы этого типа не принимаются. Разрешены: ' . implode(', ', $allowed) . '.'];
        }

        $maxSize = (int) self::limit('max_file_size');
        if ($maxSize > 0 && strlen($bytes) > $maxSize) {
            $limitText = $maxSize >= 1048576 ? round($maxSize / 1048576, 1) . ' МБ' : ceil($maxSize / 1024) . ' КБ';

            return [null, "Файл больше {$limitText}."];
        }

        $file = \CRestUtil::saveFile([$fileName, $content]);

        return is_array($file) ? [$file, null] : [null, 'Файл не удалось сохранить.'];
    }

    /**
     * Ошибки CForm::Check: [SID вопроса => текст] и общие [0 => текст]
     * → [{field, message}]. Ошибка капчи — field 'captcha'.
     */
    private static function formatErrors(array $errors): array
    {
        $captchaMessage = GetMessage('FORM_WRONG_CAPTCHA');
        $result = [];

        foreach ($errors as $key => $message) {
            $message = trim(strip_tags(str_replace(['<br>', '<br />'], ' ', (string) $message)));
            $field = is_string($key) ? $key : null;
            if ($field === null && $captchaMessage && $message === trim(strip_tags($captchaMessage))) {
                $field = 'captcha';
            }

            $result[] = ['field' => $field, 'message' => $message];
        }

        return $result;
    }

    /**
     * Поля ввода вопроса из его ответов, в порядке сортировки ответов.
     * Текстовые ответы (text, textarea, email, url, password, hidden, date,
     * file, image) — по полю на ответ, name = form_<тип>_<ID ответа>.
     * Ответы для выбора одного типа — одно поле с вариантами,
     * name = form_<тип>_<SID вопроса>, значение варианта — ID ответа.
     */
    private static function buildControls(string $questionSid, array $answers): array
    {
        $controls = [];
        $choiceIndex = [];

        foreach ($answers as $answer) {
            $type = (string) $answer['FIELD_TYPE'];
            $default = self::isDefault((string) ($answer['FIELD_PARAM'] ?? ''));

            if (!in_array($type, self::CHOICE_TYPES, true)) {
                $controls[] = [
                    'type' => $type,
                    'name' => 'form_' . $type . '_' . (int) $answer['ID'],
                    // У текстового поля «Ответ» в админке по умолчанию — пробел.
                    'label' => trim((string) ($answer['MESSAGE'] ?? '')),
                    'default' => (string) ($answer['VALUE'] ?? ''),
                    'multiple' => false,
                ];
                continue;
            }

            if (!isset($choiceIndex[$type])) {
                $controls[] = [
                    'type' => $type,
                    'name' => 'form_' . $type . '_' . $questionSid,
                    'multiple' => in_array($type, self::MULTIPLE_TYPES, true),
                    'options' => [],
                ];
                $choiceIndex[$type] = array_key_last($controls);
            }

            $controls[$choiceIndex[$type]]['options'][] = [
                'value' => (int) $answer['ID'],
                'name' => trim((string) ($answer['MESSAGE'] ?? '')),
                'selected' => $default,
            ];
        }

        return $controls;
    }

    /**
     * Вариант выбран по умолчанию, если в его параметрах есть checked или
     * selected — так это задаётся в админке и так это читает сам модуль.
     */
    private static function isDefault(string $fieldParam): bool
    {
        $fieldParam = mb_strtolower($fieldParam);

        return str_contains($fieldParam, 'checked') || str_contains($fieldParam, 'selected');
    }

    /**
     * Валидаторы формы по ID вопроса: [{name, params}], params — настройки
     * валидатора с ключами в нижнем регистре (text_len: length_from, length_to).
     */
    private static function loadValidators(int $formId): array
    {
        $result = [];

        $rs = \CFormValidator::GetListForm($formId);
        while ($validator = $rs->Fetch()) {
            $params = is_array($validator['PARAMS'] ?? null) ? $validator['PARAMS'] : [];
            $result[(int) $validator['FIELD_ID']][] = [
                'name' => (string) $validator['NAME'],
                'params' => (object) array_change_key_case($params, CASE_LOWER),
            ];
        }

        return $result;
    }

    private static function textType(string $type): string
    {
        return strtolower($type) === 'html' ? 'html' : 'text';
    }
}
