#!/usr/bin/env bash
# Проверка REST API пакета custom/rest на работающем сайте с тестовыми данными: методы, белые списки, фильтры, ошибки,
# защита от обходов, ETag, уведомления фронту. Запуск (по умолчанию — в Docker-стенде этого проекта):
#   bash local/packages/custom-rest/tests/run.sh           — проверяет то, что уже есть на стенде
#   bash local/packages/custom-rest/tests/run.sh --seed    — сначала пересоздаёт тестовые данные (tests/fixtures/seed.php)
# С --seed файл local/php_interface/config/custom-rest.local.php создаётся из tests/fixtures/custom-rest.local.php.example,
# если его нет. Ничего не сохраняет, кроме одного: на время проверки поиска выключает и обратно включает раздел «spb».
#
# Переменные окружения для другого устройства сайта (не Docker-стенд этого проекта):
#   PROJECT_ROOT  корень проекта, откуда запускаются команды (по умолчанию на три уровня выше пакета)
#   PHP_RUN       команда, которая выполняет PHP-скрипт со stdin внутри Битрикса (docker compose exec -T -u www-data php php)
#   SH_RUN        команда запуска sh там же (docker compose exec -T php sh); нужна проверке уведомлений фронту
#   WEBHOOK_CMD   команда создания вебхука (custom-rest webhook); печатает адрес вида /rest/ID/СЕКРЕТ/
#   REST_URL      готовый адрес вебхука https://хост/rest/ID/СЕКРЕТ/ — тогда WEBHOOK_CMD не нужна
#   REST_PREFIX   префикс методов, если в настройках проекта он не custom.rest
#   BITRIX_ROOT   корень сайта внутри PHP (по умолчанию /var/www/html)
set -uo pipefail
PKG=$(cd "$(dirname "$0")/.." && pwd)
PROJECT_ROOT=${PROJECT_ROOT:-$(cd "$PKG/../../.." && pwd)}
cd "$PROJECT_ROOT" || exit 1

PREFIX=${REST_PREFIX:-custom.rest}
BITRIX_ROOT=${BITRIX_ROOT:-/var/www/html}
PHP_RUN=${PHP_RUN:-docker compose exec -T -u www-data php php}
SH_RUN=${SH_RUN:-docker compose exec -T php sh}
WEBHOOK_CMD=${WEBHOOK_CMD:-docker compose exec -T -w $BITRIX_ROOT -u www-data php php local/vendor/bin/custom-rest webhook}
CONFIG=local/php_interface/config/custom-rest.local.php
FAILS=0
TOTAL=0
ok()   { TOTAL=$((TOTAL+1)); echo "  [ok]   $1"; }
fail() { TOTAL=$((TOTAL+1)); FAILS=$((FAILS+1)); echo "  [FAIL] $1"; [ -n "${2:-}" ] && echo "         $2"; }

# shellcheck disable=SC2086  # PHP_RUN — команда с аргументами, её слова разделяются намеренно
php_run() { $PHP_RUN; }

if [ "${1:-}" = "--seed" ]; then
  [ -f "$CONFIG" ] || cp "$PKG/tests/fixtures/custom-rest.local.php.example" "$CONFIG"
  echo "== тестовые данные =="
  php_run < "$PKG/tests/fixtures/seed.php" >/dev/null && ok "tests/fixtures/seed.php" || { fail "tests/fixtures/seed.php"; exit 1; }
fi

if [ -z "${REST_URL:-}" ]; then
  HTTPS_PORT=$(sed -n 's/^HTTPS_PORT=//p' .env 2>/dev/null | head -1)
  # shellcheck disable=SC2086
  path=$($WEBHOOK_CMD 2>/dev/null | grep -o '/rest/[0-9]*/[A-Za-z0-9]*/' | head -1)
  [ -n "$path" ] || { echo "Не удалось получить адрес вебхука (сайт запущен? custom-rest webhook): задайте REST_URL"; exit 1; }
  REST_URL="https://localhost:${HTTPS_PORT:-8443}${path}"
fi

# check НАЗВАНИЕ МЕТОД 'строка запроса' 'выражение Python'
# В выражении: d — весь ответ, r — d["result"], c — HTTP-код, items — r["items"], codes/names/urls — их коды, имена и url,
# err — текст ошибки.
check() {
  local name=$1 method=$2 query=$3 expr=$4 out code body full
  case $method in raw:*) full=${method#raw:} ;; *) full=$PREFIX.$method ;; esac
  out=$(curl -sk -g -w $'\n%{http_code}' "${REST_URL}${full}.json?${query}")
  code=${out##*$'\n'}; body=${out%$'\n'*}
  evaluate "$name" "$code" "$body" "$expr"
}

# post НАЗВАНИЕ МЕТОД 'JSON тела' 'выражение Python'
post() {
  local name=$1 method=$2 json=$3 expr=$4 out code body
  out=$(curl -sk -w $'\n%{http_code}' -X POST -H 'Content-Type: application/json' -d "$json" "${REST_URL}${PREFIX}.${method}.json")
  code=${out##*$'\n'}; body=${out%$'\n'*}
  evaluate "$name" "$code" "$body" "$expr"
}

evaluate() {
  local name=$1 code=$2 body=$3 expr=$4 msg
  if msg=$(CODE="$code" BODY="$body" python3 -c '
import json, os, sys
c = int(os.environ["CODE"])
try:
    d = json.loads(os.environ["BODY"])
except ValueError:
    print("не JSON: " + os.environ["BODY"][:120]); sys.exit(1)
r = d.get("result")
items = r.get("items", []) if isinstance(r, dict) else []
codes = [i.get("code") for i in items]
urls = [i.get("url") for i in items]
names = [i.get("name") for i in items]
err = d.get("error_description", "")
sys.exit(0 if eval(sys.argv[1]) else (print("c=%s err=%s" % (c, err)) or 1))
' "$expr" 2>&1); then ok "$name"; else fail "$name" "$msg"; fi
}

ARBAT_ID=$(curl -sk -g "${REST_URL}${PREFIX}.element.get.json?iblock_code=testclubs&code=arbat" | python3 -c 'import sys,json;print(json.load(sys.stdin)["result"]["id"])' 2>/dev/null)
FILE_FIELD=$(curl -sk -g "${REST_URL}${PREFIX}.form.get.json?sid=TEST_FEEDBACK" | python3 -c 'import sys,json;print([f for f in json.load(sys.stdin)["result"]["fields"] if f["sid"]=="CERTIFICATE"][0]["controls"][0]["name"])' 2>/dev/null)

echo "== методы =="
check "element.get по коду"          element.get  "iblock_code=testclubs&code=arbat" 'c == 200 and r["code"] == "arbat" and r["breadcrumbs"][-1]["type"] == "element"'
check "element.get по ID"            element.get  "iblock_code=testclubs&id=${ARBAT_ID}" 'c == 200 and r["code"] == "arbat"'
check "element.get с путём раздела"  element.get  "iblock_code=testclubs&code=arbat&section_code_path=moscow/moscow-center" 'c == 200'
check "element.get: путь чужого раздела — 404" element.get "iblock_code=testclubs&code=arbat&section_code_path=spb" 'c == 404'
check "element.get со свойствами"    element.get  "iblock_code=testclubs&code=arbat&properties[]=FORMAT&properties[]=PHOTOS&properties[]=AMENITIES" 'c == 200 and set(r["properties"]) == {"format", "photos", "amenities"} and r["properties"]["amenities"] == ["pool", "sauna", "parking"]'
check "текст с типом: element.get"   element.get  "iblock_code=testclubs&code=arbat&select[]=PREVIEW_TEXT&select[]=DETAIL_TEXT" 'c == 200 and r["detail_text"]["type"] == "html" and "подробное" in r["detail_text"]["value"] and r["preview_text"]["type"] == "text" and not any(k.endswith("_text_type") for k in r)'
check "текст с типом: section.get"   section.get  "iblock_code=testclubs&code=moscow&select[]=DESCRIPTION" 'c == 200 and r["description"] == {"value": "Клубы: Москва", "type": "text"} and "description_type" not in r'
check "текст с типом: iblock.get"    iblock.get   "iblock_code=testclubs" 'c == 200 and r["description"]["type"] == "text" and "description_type" not in r'
check "текст с типом: form.get"      form.get     "sid=TEST_FEEDBACK" 'c == 200 and set(r["description"]) == {"value", "type"} and all(set(f["title"]) == {"value", "type"} for f in r["fields"]) and "description_type" not in r'
check "element.list"                 element.list "iblock_code=testclubs" 'c == 200 and sorted(codes) == ["arbat", "nevsky", "tverskaya", "vasilievsky"] and r["meta"]["total"] == 4'
check "element.list: пагинация"      element.list "iblock_code=testclubs&limit=2&page=2" 'c == 200 and len(codes) == 2 and r["meta"]["pages"] == 2'
check "element.list: раздел"         element.list "iblock_code=testclubs&section_code_path=spb" 'c == 200 and sorted(codes) == ["nevsky", "vasilievsky"]'
check "element.list: с подразделами" element.list "iblock_code=testclubs&section_code_path=moscow&include_subsections=Y" 'c == 200 and sorted(codes) == ["arbat", "tverskaya"]'
check "element.list: порядок по свойству" element.list "iblock_code=testclubs&order[PROPERTY.AREA]=desc&select[]=CODE" 'c == 200 and codes[0] == "vasilievsky"'
check "section.get по коду"          section.get  "iblock_code=testclubs&code=moscow" 'c == 200 and r["code"] == "moscow"'
check "section.get по пути"          section.get  "iblock_code=testclubs&code_path=moscow/moscow-center" 'c == 200 and r["code"] == "moscow-center" and len(r["breadcrumbs"]) == 4'
check "section.list: корень"         section.list "iblock_code=testclubs" 'c == 200 and sorted(codes) == ["moscow", "spb"]'
check "section.tree"                 section.tree "iblock_code=testclubs" 'c == 200 and r["meta"]["total"] == 3 and r["meta"]["truncated"] is False'
check "section.tree: фильтр отсекает поддерево" section.tree "iblock_code=testclubs&filter[CODE]=moscow-center" 'c == 200 and r["items"] == [] and r["meta"]["total"] == 0'
check "breadcrumbs.get по элементу"  breadcrumbs.get "iblock_code=testclubs&element_code=arbat" 'c == 200 and [x["type"] for x in r] == ["home", "iblock", "section", "section", "element"]'
check "filter.get"                   filter.get   "iblock_code=testclubs" 'c == 200 and {"FORMAT", "AREA"} <= {i["code"] for i in r["items"]}'
check "filter.get: справочник с подписями" filter.get "iblock_code=testclubs&properties[]=AMENITIES" 'c == 200 and r["items"][0]["type"] == "directory" and [(v["value"], v["name"], v["count"]) for v in r["items"][0]["values"]] == [("pool", "Бассейн", 2), ("sauna", "Сауна", 2), ("parking", "Парковка", 2)]'
check "filter.get: выключенной записи справочника нет" filter.get "iblock_code=testclubs&properties[]=AMENITIES" 'all(v["value"] != "kids" for v in r["items"][0]["values"])'
check "iblock.get: SEO страницы списка" iblock.get "iblock_code=testclubs" 'c == 200 and r["url"] == "/clubs/" and r["seo"]["h1"] == "Все клубы сети" and r["seo"]["title"].startswith("Фитнес-клубы") and r["description"] != "" and [x["type"] for x in r["breadcrumbs"]] == ["home", "iblock"]'
check "iblock.get: инфоблок не из белого списка" iblock.get "iblock_code=testleads" 'c == 400'
check "filter.get: счётчики при выборе" filter.get "iblock_code=testclubs&filter[PROPERTY.FORMAT]=premium" 'c == 200 and r["meta"]["total"] == 2'
check "search"                       search       "q=бассейн" 'c == 200 and len(items) >= 1 and all("snippet" in i for i in items)'
check "search: запрос без букв — пусто" search    "q=!!!" 'c == 200 and r["items"] == [] and "notice" in r["meta"]'
check "search.suggest"               search.suggest "q=арб" 'c == 200 and "Арбат" in names'
check "search.correct: опечатка"      search.correct "q=басейн" 'c == 200 and r["corrected"] == "бассейн"'
check "search.correct: слово верное"  search.correct "q=бассейн" 'c == 200 and r["corrected"] is None'
check "search.correct: пустой запрос" search.correct "q=" 'c == 400'
check "hl.list"                      hl.list      "hl=TestAmenities" 'c == 200 and [i["uf_xml_id"] for i in r["items"]] == ["pool", "sauna", "parking"]'
check "hl.get по xml_id"             hl.get       "hl=TestAmenities&xml_id=pool" 'c == 200 and r["uf_name"] == "Бассейн"'
check "form.get"                     form.get     "sid=TEST_FEEDBACK" 'c == 200 and r["sid"] == "TEST_FEEDBACK" and len(r["fields"]) > 5'
check "captcha.get"                  captcha.get  "" 'c == 200 and r["sid"] != ""'

echo "== фильтры =="
L="iblock_code=testclubs&select[]=CODE"
check "filter: подстрока"            element.list "$L&filter[%25NAME]=ев" 'sorted(codes) == ["nevsky", "vasilievsky"]'
check "filter: не равно"             element.list "$L&filter[!CODE]=arbat" 'sorted(codes) == ["nevsky", "tverskaya", "vasilievsky"]'
check "filter: диапазон числа"       element.list "$L&filter[%3E%3DPROPERTY.AREA]=1000&filter[%3C%3DPROPERTY.AREA]=1250" 'codes == ["tverskaya"]'
check "filter: между"                element.list "$L&filter[%3E%3CPROPERTY.AREA][]=900&filter[%3E%3CPROPERTY.AREA][]=1200" 'sorted(codes) == ["arbat", "tverskaya"]'
check "filter: список свойства (XML_ID)" element.list "$L&filter[PROPERTY.FORMAT]=premium" 'sorted(codes) == ["arbat", "nevsky"]'
check "filter: справочник"           element.list "$L&filter[PROPERTY.AMENITIES]=pool" 'sorted(codes) == ["arbat", "nevsky"]'
check "filter: справочник, любое из" element.list "$L&filter[@PROPERTY.AMENITIES][]=sauna&filter[@PROPERTY.AMENITIES][]=parking" 'sorted(codes) == ["arbat", "tverskaya", "vasilievsky"]'
check "filter: отрицание «без бассейна»" element.list "$L&filter[!PROPERTY.AMENITIES]=pool" 'sorted(codes) == ["tverskaya", "vasilievsky"]'
check "filter: отрицание по привязке" element.list "$L&filter[!@PROPERTY.AMENITIES][]=pool&filter[!@PROPERTY.AMENITIES][]=sauna" 'codes == ["tverskaya"]'
check "filter: два условия — И"      element.list "$L&filter[PROPERTY.FORMAT]=premium&filter[PROPERTY.AMENITIES]=sauna" 'codes == ["arbat"]'
check "filter: группа ИЛИ"           element.list "$L&filter[0][LOGIC]=OR&filter[0][PROPERTY.FORMAT]=premium&filter[0][%3CPROPERTY.AREA]=1000" 'sorted(codes) == ["arbat", "nevsky"]'
check "filter: дата ISO"             element.list "$L&filter[%3E%3DDATE_CREATE]=2020-01-01" 'len(codes) == 4'
check "hl: список по XML_ID"         hl.list      "hl=TestAmenities&filter[UF_TYPE]=service&select[]=UF_XML_ID" 'len(items) == 1 and items[0]["uf_xml_id"] == "parking"'
check "hl: отрицание по множественному" hl.list   "hl=TestAmenities&filter[!UF_TAGS]=25%20м&select[]=UF_XML_ID" '[i["uf_xml_id"] for i in items] == ["sauna", "parking"]'

echo "== описание API (OpenAPI) =="
python3 "$PKG/tools/openapi.py" --prefix "$PREFIX" --check >/dev/null 2>&1 && ok "docs/openapi.json актуален (tools/openapi.py)" || fail "docs/openapi.json устарел: python3 tools/openapi.py --prefix $PREFIX"
registered=$(curl -sk "${REST_URL}methods.json" | PREFIX="$PREFIX" python3 -c 'import os,sys,json;print("\n".join(sorted(m for m in json.load(sys.stdin)["result"] if m.startswith(os.environ["PREFIX"] + "."))))')
documented=$(PKG="$PKG" python3 -c 'import json,os;print("\n".join(sorted(p[1:-5] for p in json.load(open(os.environ["PKG"] + "/docs/openapi.json"))["paths"])))')
if [ -n "$registered" ] && [ "$registered" = "$documented" ]; then ok "методы в openapi.json совпадают с зарегистрированными ($(echo "$registered" | wc -l | tr -d ' '))"; else fail "openapi.json и зарегистрированные методы расходятся" "$(diff <(echo "$registered") <(echo "$documented") | head -6)"; fi

echo "== ETag и Last-Modified =="
etag_url="${REST_URL}${PREFIX}.element.get.json?iblock_code=testclubs&code=nevsky"
etag_headers=$(curl -sk -D - -o /dev/null "$etag_url")
etag=$(printf '%s' "$etag_headers" | sed -n 's/^[Ee][Tt][Aa][Gg]: //p' | tr -d '\r')
last_modified=$(printf '%s' "$etag_headers" | sed -n 's/^[Ll]ast-[Mm]odified: //p' | tr -d '\r')
[ -n "$etag" ] && [ -n "$last_modified" ] && ok "ответ отдаёт ETag и Last-Modified" || fail "нет ETag или Last-Modified" "$etag_headers"
result=$(curl -sk -o /dev/null -w '%{http_code} %{size_download}' -H "If-None-Match: $etag" "$etag_url")
[ "$result" = "304 0" ] && ok "If-None-Match совпал — 304 без тела" || fail "If-None-Match совпал" "ответ: $result"
result=$(curl -sk -o /dev/null -w '%{http_code}' -H 'If-None-Match: W/"deadbeef"' "$etag_url")
[ "$result" = "200" ] && ok "чужой ETag — полный ответ" || fail "чужой ETag" "ответ: $result"
result=$(curl -sk -o /dev/null -w '%{http_code}' -H "If-Modified-Since: $last_modified" "$etag_url")
[ "$result" = "304" ] && ok "If-Modified-Since не раньше построения — 304" || fail "If-Modified-Since" "ответ: $result"
result=$(curl -sk -o /dev/null -w '%{http_code}' -H 'If-Modified-Since: Mon, 01 Jan 2024 00:00:00 GMT' "$etag_url")
[ "$result" = "200" ] && ok "If-Modified-Since раньше построения — полный ответ" || fail "If-Modified-Since раньше" "ответ: $result"
result=$(curl -sk -o /dev/null -w '%{http_code}' -X POST -H "If-None-Match: $etag" -H 'Content-Type: application/json' -d '{"iblock_code":"testclubs","code":"nevsky"}' "${REST_URL}${PREFIX}.element.get.json")
[ "$result" = "200" ] && ok "POST не отвечает 304" || fail "POST и If-None-Match" "ответ: $result"
result=$(curl -sk -g -H "If-None-Match: $etag" "${REST_URL}batch.json?cmd[a]=${PREFIX}.element.get?iblock_code=testclubs%26code=nevsky" | python3 -c 'import sys,json;print(json.load(sys.stdin)["result"]["result"]["a"]["code"])' 2>&1)
[ "$result" = "nevsky" ] && ok "в batch условные заголовки не действуют" || fail "batch и If-None-Match" "$result"
sitemap_etag=$(curl -sk -D - -o /dev/null "${REST_URL}${PREFIX}.sitemap.get.json?name=static" | sed -n 's/^[Ee][Tt][Aa][Gg]: //p' | tr -d '\r')
[ -n "$sitemap_etag" ] && ok "sitemap.get тоже отдаёт ETag" || fail "sitemap.get: нет ETag"

echo "== карта сайта =="
check "sitemap.index: карты"          sitemap.index "" 'c == 200 and [i["name"] for i in items] == ["testclubs", "testtrainers", "static"] and items[0]["total"] == 4 and items[0]["pages"] == 1'
check "sitemap.get: страницы инфоблока" sitemap.get "name=testclubs" 'c == 200 and urls == ["/clubs/", "/clubs/moscow/", "/clubs/spb/", "/clubs/spb/nevsky/"] and r["meta"]["total"] == 4'
check "sitemap.get: исключён раздел с подразделами и клубами" sitemap.get "name=testclubs" 'not any("moscow-center" in u or "arbat" in u or "tverskaya" in u for u in urls)'
check "sitemap.get: исключён элемент по свойству" sitemap.get "name=testclubs" '"/clubs/spb/vasilievsky/" not in urls'
check "sitemap.get: нет скрытого"     sitemap.get  "name=testclubs" 'not any("kazan" in u or "draft" in u or "closed" in u or "future" in u or "north" in u for u in urls)'
check "sitemap.get: у адресов есть lastmod и тип" sitemap.get "name=testclubs" 'all(i["lastmod"] and i["type"] in ("iblock", "section", "element") for i in items)'
check "sitemap.get: картинок по умолчанию нет" sitemap.get "name=testclubs" 'all("images" not in i for i in items)'
check "sitemap.get: images=Y"        sitemap.get  "name=testclubs&images=Y" 'all(isinstance(i["images"], list) for i in items) and any(i["images"] for i in items)'
check "sitemap.get: другой инфоблок" sitemap.get  "name=testtrainers" 'c == 200 and urls[0] == "/trainers/" and len(urls) == 4'
check "sitemap.get: статические страницы" sitemap.get "name=static" 'c == 200 and urls == ["/", "/contacts/"] and items[1]["lastmod"] == "2026-01-15"'
check "sitemap.get: нет name"        sitemap.get  "" 'c == 400'
check "sitemap.get: карта не из белого списка" sitemap.get "name=testleads" 'c == 400'
check "sitemap.get: страницы за пределами — 404" sitemap.get "name=testclubs&page=2" 'c == 404'

echo "== что не отдаётся =="
check "инфоблок не из белого списка" element.list "iblock_code=testleads" 'c == 400'
check "форма не из белого списка"    form.get     "sid=TEST_SECRET" 'c == 400'
check "highload-блок не из белого списка" hl.list "hl=TestPartners" 'c == 400'
check "черновик по коду — 404"       element.get  "iblock_code=testclubs&code=draft" 'c == 404'
check "будущая публикация — 404"     element.get  "iblock_code=testclubs&code=future" 'c == 404'
check "истёкшая публикация — 404"    element.get  "iblock_code=testclubs&code=closed" 'c == 404'
check "элемент скрытого раздела — 404" element.get "iblock_code=testclubs&code=kazan-arena" 'c == 404'
check "в списке нет скрытого"        element.list "iblock_code=testclubs&limit=50" 'not ({"draft", "future", "closed", "kazan-arena"} & set(codes))'
check "раздел скрыт через родителя"  section.get  "iblock_code=testclubs&code=kazan-center" 'c == 404'
check "служебное свойство"           element.get  "iblock_code=testclubs&code=arbat&properties[]=INTERNAL_NOTE" 'c == 400'
check "служебное поле highload"      hl.list      "hl=TestAmenities&select[]=UF_INTERNAL" 'c == 400'
check "выключенная запись highload — 404" hl.get  "hl=TestAmenities&xml_id=kids" 'c == 404'
check "в списке highload нет выключенной" hl.list "hl=TestAmenities" '"kids" not in [i["uf_xml_id"] for i in items]'
check "поле активности highload не фильтруется" hl.list "hl=TestAmenities&filter[UF_ACTIVE]=0" 'c == 400'
check "ACTIVE=N не вытащит скрытое"  element.list "$L&filter[ACTIVE]=N" 'c == 200 and codes == []'
check "фильтр через связанную сущность" element.list "$L&filter[0][!CREATED_BY_USER.LOGIN]=x" 'c == 400'
check "в поиске нет скрытого разделом" search     "q=Арена" 'c == 200 and items == []'
check "стандартный метод iblock закрыт" raw:iblock.Element.list "iblockId=1" 'c in (400, 401, 403, 404)'

echo "== ошибки и ограничения =="
check "нет iblock_code"              element.list ""  'c == 400'
check "параметр массивом"            element.list "iblock_code[]=testclubs" 'c == 400'
check "и id, и code"                 element.get  "iblock_code=testclubs&id=1&code=arbat" 'c == 400'
check "оператор *"                   element.list "$L&filter[*NAME]=арбат" 'c == 400'
check "оператор ~"                   element.list "$L&filter[~NAME]=арбат" 'c == 400'
check "не число в числовом фильтре"  element.list "$L&filter[%3E%3DPROPERTY.AREA]=abc" 'c == 400'
check "не число в ID"                element.list "$L&filter[ID]=abc" 'c == 400'
check "неверная дата"                element.list "$L&filter[%3E%3DDATE_CREATE]=завтра" 'c == 400'
check "неверное направление order"   element.list "$L&order[ID]=вниз" 'c == 400'
check "сортировка по множественному" element.list "$L&order[PROPERTY.FEATURES]=asc" 'c == 400'
check "tree: depth вне диапазона"    section.tree "iblock_code=testclubs&depth=99" 'c == 400'
check "пустой поисковый запрос"      search       "q=" 'c == 400'

echo "== формы =="
post "form.add: пустая форма — ошибки заполнения" form.add '{"sid":"TEST_FEEDBACK","values":{}}' 'c == 200 and r["success"] is False and len(r["errors"]) > 0'
post "form.add: форма не из белого списка" form.add '{"sid":"TEST_SECRET","values":{}}' 'c == 400'
post "form.add: файл .php не принимается" form.add "{\"sid\":\"TEST_FEEDBACK\",\"values\":{\"${FILE_FIELD}\":[\"shell.php\",\"PD9waHA=\"]}}" 'c == 200 and any(e["field"] == "CERTIFICATE" for e in r["errors"])'
post "form.add: ловушка hp — успех без сохранения" form.add '{"sid":"TEST_FEEDBACK","values":{},"hp":"spam"}' 'c == 200 and r == {"success": True, "result_id": 0}'

echo "== поиск следует за активностью раздела =="
toggle() {
  php_run <<PHP
<?php
\$_SERVER['DOCUMENT_ROOT'] = getenv('BITRIX_ROOT') ?: '/var/www/html';
define('NOT_CHECK_PERMISSIONS', true); define('NO_AGENT_CHECK', true);
require \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('iblock');
\$s = \Bitrix\Iblock\SectionTable::getList(['select' => ['ID'], 'filter' => ['=CODE' => 'spb'], 'limit' => 1])->fetch();
(new CIBlockSection())->Update((int) \$s['ID'], ['ACTIVE' => '$1']);
PHP
}
trap 'toggle Y >/dev/null 2>&1' EXIT
toggle N >/dev/null 2>&1
check "СПб выключен: «Невский» не находится" search "q=Невский" 'c == 200 and items == []'
check "СПб выключен: подсказок нет"  search.suggest "q=нев" 'c == 200 and items == []'
toggle Y >/dev/null 2>&1
check "СПб включён: «Невский» находится" search "q=Невский" 'c == 200 and "Невский" in names'

echo "== уведомление фронта об изменении контента =="
revalidate_test() {
  local backup=/tmp/rest-api.local.php.backup log msg
  cp "$CONFIG" "$backup" || { fail "резервная копия настроек"; return; }
  python3 - "$CONFIG" <<'PY'
import sys
path = sys.argv[1]
text = open(path, encoding="utf-8").read()
marker = "    'sitemap'"
assert marker in text
text = text.replace(marker, "    'revalidate' => ['url' => 'http://127.0.0.1:8099/hook', 'secret' => 'testsecret', 'timeout' => 2],\n" + marker, 1)
open(path, "w", encoding="utf-8").write(text)
PY
  # Приёмник уведомлений: встроенный сервер PHP в контейнере пишет тела и заголовки в журнал.
  $SH_RUN -c 'rm -rf /tmp/recv; mkdir -p /tmp/recv; printf "%s\n" \
    "<?php" \
    "\$h = [];" \
    "foreach (\$_SERVER as \$k => \$v) { if (str_starts_with(\$k, \"HTTP_X_REST\")) { \$h[\$k] = \$v; } }" \
    "file_put_contents(\"/tmp/recv/log.jsonl\", json_encode([\"headers\" => \$h, \"body\" => file_get_contents(\"php://input\")], JSON_UNESCAPED_UNICODE) . \"\n\", FILE_APPEND);" \
    "echo \"ok\";" > /tmp/recv/index.php
    (php -S 127.0.0.1:8099 -t /tmp/recv >/tmp/recv/server.log 2>&1 & echo $! > /tmp/recv/pid)' >/dev/null 2>&1
  sleep 1
  php_run <<'PHP' >/dev/null 2>&1
<?php
$_SERVER['DOCUMENT_ROOT'] = getenv('BITRIX_ROOT') ?: '/var/www/html';
define('NOT_CHECK_PERMISSIONS', true); define('NO_AGENT_CHECK', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('iblock');
$el = \Bitrix\Iblock\ElementTable::getList(['select' => ['ID'], 'filter' => ['=CODE' => 'nevsky'], 'limit' => 1])->fetch();
(new CIBlockElement())->Update((int) $el['ID'], ['SORT' => 777]);
PHP
  sleep 1
  log=$($SH_RUN -c 'cat /tmp/recv/log.jsonl' 2>/dev/null)
  $SH_RUN -c 'kill "$(cat /tmp/recv/pid)"; rm -rf /tmp/recv' >/dev/null 2>&1
  cp "$backup" "$CONFIG"; rm -f "$backup"

  if msg=$(LOG="$log" python3 -c '
import hashlib, hmac, json, os, sys
lines = [json.loads(line) for line in os.environ["LOG"].splitlines() if line.strip()]
if not lines:
    print("уведомление не пришло"); sys.exit(1)
entry = lines[-1]
body = json.loads(entry["body"])
ts = entry["headers"]["HTTP_X_REST_TIMESTAMP"]
expected = "sha256=" + hmac.new(b"testsecret", (ts + "." + entry["body"]).encode(), hashlib.sha256).hexdigest()
problems = []
if entry["headers"].get("HTTP_X_REST_SIGNATURE") != expected: problems.append("подпись не сошлась")
if body["events"][0]["type"] != "element.changed": problems.append("тип события")
if set(body["urls"]) != {"/clubs/spb/nevsky/", "/clubs/spb/", "/clubs/"}: problems.append("адреса: %s" % body["urls"])
if "iblock:testclubs" not in body["tags"]: problems.append("теги: %s" % body["tags"])
print("; ".join(problems)); sys.exit(1 if problems else 0)
' 2>&1); then ok "правка элемента: фронту ушло уведомление с верной подписью, адресами и тегами"; else fail "уведомление фронту" "$msg"; fi
}
revalidate_test

echo
if [ "$FAILS" = 0 ]; then echo "Всё в порядке: $TOTAL проверок"; else echo "Провалено проверок: $FAILS из $TOTAL"; fi
[ "$FAILS" = 0 ]
