# Деплой 3 — поиск по покрытиям в калькуляторе (auth + online)

Часть арки — см. `docs/plans/tools-mixing-calculator-overview.md`. Ветка
`feat/tools-coating-search`, стеком поверх Деплоя 2. Дизайн (состояния из/без пропорции,
приманка) согласован ранее.

## Суть

Для ЗАЛОГИНЕННЫХ на `/tools/mix` — блок «Из покрытия»: typeahead по покрытиям → выбор →
пропорция покрытия автозаполняет калькулятор. Аноним видит приманку «Мы, кажется, не знакомы».
Требует сеть → online-only, офлайн деградирует (ручной калькулятор работает).

## Подход: соотношение приходит СТАНДАРТНЫМ ПОИСКОМ (без нового эндпоинта)

Ключевое решение (по заказчику): не плодим отдельный `mixing-ratio` эндпоинт — соотношение
едет в ответе существующего typeahead-поиска (`suggest` → `SearchCoatings` → `findByFilter`).
`SearchCoatingsQueryHandler` строит item прямо из агрегата `Coating`, поэтому `getMixingRatio()`
под рукой.

### Бэк (правки существующего поиска)
- `Coatings/Application/UseCase/Query/SearchCoatings/SearchCoatingsQueryHandler.php`: в item
  добавить `'mixingRatio' => $coating->getMixingRatio()?->jsonSerialize()` (форма
  `{volume:number[]|null, mass:number[]|null}` или `null` у однокомпонентных). Обновить phpdoc
  `@return`-шейпа.
- `Coatings/Infrastructure/Controller/Coating/SuggestAction.php`: прокинуть `mixingRatio` в
  выходной item (сейчас он вручную перечисляет поля id/title/base/dftMin/dftMax).
- Гейт готов: `suggest` уже под `/cabinet` (`IS_AUTHENTICATED`). Ничего нового вешать не нужно.
- Данных нет ни у кого → `mixingRatio` придёт `null` для всех (кейс «отказ», см. верификацию).

### Фронт
- `async_typeahead_controller.js` — аддитивно (обратно совместимо, переиспользуемо):
  1. в `_onInput` тащить весь item в whitelist (не схлопывать до `{value,id}`), чтобы доп. поля
     (`mixingRatio` и пр.) сохранялись на теге;
  2. на `add` диспатчить `this.dispatch('select', { detail: { item } })` → событие
     `async-typeahead:select` (существующие потребители, читающие id/title, не затронуты).
- `tools/mix.html.twig`: рядом с `{% if not app.user %}`-CTA добавить `{% if app.user %}`-блок
  «Из покрытия»: `async-typeahead` над `<select>` (`endpoint = path('app_cabinet_coating_coating_suggest')`,
  `data-action="async-typeahead:select->mix-calculator#applyFromCoating"`), чип выбранного
  покрытия с «✕ убрать», сегмент «Объём/Масса» (виден только если обе базы), плашка-сообщение.
- `mix_calculator_controller.js`:
  - `applyFromCoating(event)` — берёт `event.detail.item.mixingRatio`:
    - есть база(ы) → выбрать (сегмент если обе, по умолчанию объём), довести число строк до
      `parts.length`, проставить `.mix-part`, **залочить** части, `recompute()`, тег «из покрытия»;
    - `{volume:null,mass:null}` → плашка «У этого покрытия нет данных о соотношении — введите
      вручную», НЕ лочить, чип оставить;
  - `locked` состояние: части `readonly`, add/remove выключены, правится только количество;
  - «✕ убрать покрытие» → снять lock, очистить чип/сегмент/плашку → ручной режим;
  - смена сегмента Объём/Масса → перезалить части из соответствующей базы.
- Офлайн: suggest недоступен → подсказок нет; показать ненавязчиво «поиск доступен онлайн»
  (или просто пустой список). Ручной калькулятор работает всегда.

## CSS
Состояния (locked/tag/плашка) — на существующих токенах, монохром `bi-*`; плашка — паттерн
`.msg`/`.msg-warning` из `form.css`. Новых стилей минимум.

## Развилки (мои решения — поправь)
1. volume/mass по умолчанию, если обе базы — **объём**.
2. Триггер — **авто по выбору** (через `async-typeahead:select`). Кнопки «Подставить» нет.

## Верификация (порядок — по заказчику)
1. **Сначала отказ** (данных нет): выбираешь любое покрытие → `mixingRatio` = `{volume:null,
   mass:null}` → плашка «нет данных, введите вручную».
   - Функц.-тест: `GET /cabinet/coating/coating/suggest?q=…` (авторизованным) → в items есть ключ
     `mixingRatio`; для покрытия без соотношения = `null`; анониму — редирект на login (гейт).
2. **Потом успех**: засеять `mixing_ratio` паре покрытий (UI-формы нет — отдельная задача).
   Сид — SQL-сниппет (дам) или разовая `bin/console`-команда; НЕ часть фичи. Браузер:
   locked-состояние, объём/масса, пересчёт от количества.
- `./run check` + `yarn dev` + браузер (десктоп/мобайл).

## Файлы
- Правки бэк: `SearchCoatingsQueryHandler.php`, `SuggestAction.php` (+ функц.-тест suggest).
- Правки фронт: `async_typeahead_controller.js` (payload+событие), `tools/mix.html.twig`
  (блок под app.user), `mix_calculator_controller.js` (apply/lock/убрать/база),
  `components/tools.css` (состояния).
- Новых бэкенд-классов/эндпоинтов НЕТ.

## Отложено
- UI-форма редактирования `mixing_ratio` в карточке покрытия (отдельная задача).
- Логин-возврат (`docs/plans/login-return-path.md`) — усиливает воронку «вход с /tools/mix».
