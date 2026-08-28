# Деплой 4 «Единая entity-карточка + списки»

> **Для исполнителя:** пошагово, с визуальной проверкой после каждой задачи в обеих темах и на узком экране. «Тест» = сборка (`yarn dev`) + браузер. PHP-тесты только если задета PHP-логика (в этом деплое бэкенд не трогаем — данные в DTO уже есть).

**Цель:** привести карточки списков покрытий/систем/документов к единому визуальному виду (медиа-слот + заголовок + описание + мета + бейджи + теги + kebab), сохранив контракты сравнения/превью; освежить трей сравнения. Бэкенд не меняем — нужных данных в DTO достаточно.

**Архитектура:** общий CSS-компонент `.ecard` (медиа-слот, тело, бейджи, теги) — один визуальный контракт для трёх сущностей; каждая сущность имеет свой batch-партиал с этими классами и своими данными (карточки различаются содержимым медиа-слота и меты, а не формой). Монограм связующего — из `CoatingDTO.base` (+ `isZincRich`) в Twig, без изменений бэкенда.

**Tech Stack:** Twig, Bootstrap 5.3, Stimulus (compare-tray, coating-preview-loader, infinite-list), CSS-токены (Деплой 1).

**Spec:** `docs/plans/frontend-redesign-design.md` (раздел 5 «Единая entity-карточка», 6.2 «Список»). Референс-макет: `.superpowers/brainstorm/*/content/list-final.html`, `list-coatings-v3.html`.

## Global Constraints

- Бэкенд/DTO НЕ меняем (данных хватает: CoatingDTO — `base`, `isZincRich`, `possibleColors[].hex`, `isTintable`, `tags`, `matchedSubstances`; CoatingSystemDTO — `layers[].colorHex`, `documentCount`, `compliance[]`, `tags`, `totalDft`; DocumentDTO — `kind/kindLabel`, `expiresAt`, `isExpired`, `issuerTitle`). У документов тегов нет — на карточке их не показываем.
- Сохранить контракты, которые слушают Stimulus-контроллеры (иначе сломаются сравнение/превью):
  - Покрытие: чекбокс `data-compare-id="{{ coating.id }}"` + `data-action="change->compare-tray#toggle"`; триггер превью `data-action="click->coating-preview-loader#open" data-coating-id="{{ coating.id }}"`; тег-ссылки фильтра (as-is).
  - Система: существующий триггер превью системы и `data-system-id` — сохранить.
- Фасеты/фильтры-offcanvas и тулбар (`above_list`) НЕ трогаем — переверстываем только batch-партиалы карточек и трей.
- Партиал карточек используется и для первой страницы, и для `?partial=1` (infinite-scroll) — разметка одного элемента должна работать в обоих.
- Монограм связующего — решение по кодам см. «Открытые точки»; дефолт: ISO-код (`EP/PUR/AK/AY/ESI/PAS/PS/FEVE`) + спецслучай `ESI → ЦС`.
- Коммиты — по режиму исполнения.

## Карта файлов

- Create: `app/assets/styles/components/entity-card.css` — общий `.ecard` и его части (медиа-слоты, бейджи, теги, kebab, FAB).
- Modify: `app/assets/styles/app.css` — импорт `entity-card.css`.
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_cards_batch.html.twig` — карточка покрытия.
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_list_cards.html.twig` — карточка системы.
- Modify: `app/src/Shared/Infrastructure/Templates/admin/certificate/document/_list_cards.html.twig` — карточка документа.
- Modify: `app/assets/controllers/compare_tray_controller.js` + трей-разметка в `admin/coating/coating/index.html.twig` — трей с чипами-названиями.
- (Медиа-слот покрытия — монограм) правку делаем инлайн в Twig карточки; отдельный Twig-фильтр — только если выберем кастомный маппинг (см. развилку).

---

## Task 1: CSS-компонент entity-card

**Files:**
- Create: `app/assets/styles/components/entity-card.css`
- Modify: `app/assets/styles/app.css`

- [ ] **Step 1: Создать `entity-card.css`** — классы под макет (`.ecard`, медиа-слоты `.ecard-mono`/`.ecard-media`, тело, `.ecard-badges`, `.ecard-tags`, `.ecard-kebab`, компакт-чекбокс сравнения, FAB). Значения — из референс-макета (list-final.html), на токенах Деплоя 1.

```css
/* Единая entity-карточка для списков покрытий/систем/документов. Один визуальный
   контракт (медиа-слот + тело + мета + бейджи + теги + kebab); содержимое
   медиа-слота и меты различается по сущности. На токенах Деплоя 1. */
.ecard {
    background: var(--surface); border: 1px solid var(--bs-border-color); border-radius: 14px;
    padding: 13px; box-shadow: var(--shadow, 0 1px 2px rgba(16,24,40,.05));
    display: flex; gap: 12px; position: relative;
    transition: transform .16s var(--ease-emph), box-shadow .16s var(--ease-emph), border-color .16s var(--ease-emph);
}
.ecard:hover { transform: translateY(-1px); border-color: var(--border-strong, var(--bs-border-color)); }
.ecard.is-selected { border-color: var(--bs-primary); box-shadow: 0 0 0 1px var(--bs-primary); }

/* Медиа: монограм связующего + цветная полоса (покрытие). */
.ecard-mono {
    width: 46px; height: 46px; border-radius: 11px; flex-shrink: 0; position: relative; overflow: hidden;
    background: var(--sunken); border: 1px solid var(--bs-border-color);
    display: grid; place-items: center;
}
.ecard-mono .lbl { font-size: 15px; font-weight: 800; letter-spacing: -.02em; color: var(--bs-body-color); margin-bottom: 6px; }
.ecard-mono .strip { position: absolute; left: 0; right: 0; bottom: 0; height: 8px; display: flex; }
.ecard-mono .strip i { flex: 1; display: block; }
.ecard-mono .zn { position: absolute; top: 2px; right: 2px; font-size: 8px; font-weight: 800; color: var(--warn); background: color-mix(in srgb, var(--warn) 20%, transparent); border-radius: 4px; padding: 0 3px; }

/* Медиа: иконка (система/документ). */
.ecard-media { width: 46px; height: 46px; border-radius: 11px; flex-shrink: 0; background: var(--sunken); border: 1px solid var(--bs-border-color); color: var(--bs-body-color); display: grid; place-items: center; }
.ecard-media .bi { font-size: 20px; }
.ecard-media.is-ok { color: var(--ok); }

.ecard-body { flex: 1; min-width: 0; }
.ecard-head { display: flex; align-items: flex-start; gap: 8px; }
.ecard-title { flex: 1; font-weight: 650; font-size: 14.5px; letter-spacing: -.01em; line-height: 1.25; word-break: break-word; }
.ecard-desc { font-size: 12.5px; color: var(--bs-secondary-color); line-height: 1.35; margin-top: 3px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.ecard-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 6px; font-size: 11.5px; color: var(--faint, var(--bs-secondary-color)); font-weight: 500; }
.ecard-meta .sw { width: 12px; height: 12px; border-radius: 3px; display: inline-block; border: 1px solid rgba(0,0,0,.15); }

.ecard-badges { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; margin-top: 8px; }
.ecard-badges .b-ok { font-size: 11px; font-weight: 600; color: var(--ok); background: var(--ok-subtle); border-radius: 6px; padding: 2px 7px; }
.ecard-badges .b-ok-lbl { font-size: 11px; font-weight: 600; color: var(--ok); }
.ecard-badges .b-iso { font-size: 11px; font-weight: 700; color: var(--bs-primary); background: var(--accent-subtle); border-radius: 6px; padding: 2px 7px; }

.ecard-tags { display: flex; gap: 5px; margin-top: 8px; overflow-x: auto; }
.ecard-tags::-webkit-scrollbar { height: 0; }
.ecard-tags .tag { flex-shrink: 0; font-size: 11px; font-weight: 500; color: var(--bs-secondary-color); background: var(--sunken); border: 1px solid var(--bs-border-color); border-radius: 6px; padding: 2px 8px; text-decoration: none; }
.ecard-tags .tag.is-active { background: var(--accent-subtle); color: var(--bs-primary); border-color: var(--bs-primary); }

.ecard-kebab { flex-shrink: 0; }

/* Компакт-чекбокс сравнения в углу карточки. */
.ecard-cmp { position: absolute; top: 11px; right: 11px; }

/* FAB создания (мобайл) — плавающая кнопка над таб-панелью. */
.fab-create {
    position: fixed; right: 18px; bottom: calc(78px + env(safe-area-inset-bottom, 0px)); z-index: 1030;
    width: 54px; height: 54px; border-radius: 17px; display: grid; place-items: center;
    background: var(--bs-primary); color: #fff; border: 0; box-shadow: 0 10px 24px rgba(47,111,235,.4);
}
.fab-create .bi { font-size: 26px; }
@media (min-width: 992px) { .fab-create { display: none; } }
```

- [ ] **Step 2: Импорт в `app.css`** (после app-shell): `@import "components/entity-card.css";`
- [ ] **Step 3: Собрать.** `cd app && yarn dev`. Классы доступны (визуально применятся в Task 2+).
- [ ] **Step 4: Коммит.** `git commit -m "CSS единой entity-карточки: медиа-слот, бейджи, теги, kebab, FAB на токенах"`

---

## Task 2: Карточка покрытия (канонический вид)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/coating/coating/_coating_cards_batch.html.twig`

- [ ] **Step 1: Переверстать элемент карточки** под `.ecard`. Монограм — ISO-код (`coating.base`) со спецслучаем `ESI → ЦС`; цветная полоса — первые до 5 `possibleColors[].hex` (если пусто и `isTintable` — нейтральная полоса/иконка палитры); мета — «N цветов» (или «Колеруемое»); бейджи «Стойкое к» из `matchedSubstances` (если есть); теги — как раньше (ссылки-фильтры, активные подсвечены); kebab заменяет три кнопки edit/delete/duplicate (через существующий `components/edit_delete.html.twig` внутри dropdown или как есть — см. ниже). Сохранить: чекбокс сравнения (`data-compare-id` + toggle), превью-триггер на заголовок/описание (`coating-preview-loader#open`).

Полная новая разметка элемента (в цикле `{% for coating in coatings %}`):

```twig
    {% set mono = coating.base ?? '—' %}{# монограм = ISO-код как есть (EP/PUR/ESI/AK…), решение владельца #}
    {% set colorHexes = coating.possibleColors|map(c => c.hex)|slice(0, 5) %}
    <div class="ecard">
        {# Сравнение — компакт-чекбокс в углу; контракт compare-tray сохранён. #}
        <div class="ecard-cmp form-check m-0">
            <input type="checkbox" class="form-check-input m-0"
                   data-compare-id="{{ coating.id }}"
                   data-action="change->compare-tray#toggle"
                   aria-label="Добавить к сравнению">
        </div>

        {# Медиа: монограм связующего + цветная полоса. #}
        <div class="ecard-mono">
            <span class="lbl">{{ mono }}</span>
            {% if coating.isZincRich and coating.base != 'ESI' %}<span class="zn">Zn</span>{% endif %}
            <span class="strip">
                {% if colorHexes|length > 0 %}
                    {% for hex in colorHexes %}<i style="background: {{ hex }}"></i>{% endfor %}
                {% else %}
                    <i style="background: var(--bs-border-color)"></i>
                {% endif %}
            </span>
        </div>

        <div class="ecard-body">
            <div class="ecard-head">
                {# Превью открывается кликом по заголовку/описанию (контракт сохранён). #}
                <div class="ecard-title" style="cursor:pointer"
                     data-action="click->coating-preview-loader#open"
                     data-coating-id="{{ coating.id }}">{{ coating.title }}</div>
                {% if canEdit %}
                    <div class="ecard-kebab dropdown">
                        <button class="btn btn-sm btn-link text-body-secondary p-1" type="button"
                                data-bs-toggle="dropdown" aria-label="Действия">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ path('app_cabinet_coating_coating_update', {id: coating.id}) }}"><i class="bi bi-pencil me-2"></i>Редактировать</a></li>
                            <li><a class="dropdown-item" href="{{ path('app_cabinet_coating_coating_create', {duplicate: coating.id}) }}"><i class="bi bi-files me-2"></i>Дублировать</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="{{ path('app_cabinet_coating_coating_delete', {id: coating.id}) }}"
                                      onsubmit="return confirm('Удалить покрытие «{{ coating.title|e('js') }}»?')">
                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Удалить</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                {% endif %}
            </div>

            <div class="ecard-desc"
                 data-action="click->coating-preview-loader#open" data-coating-id="{{ coating.id }}"
                 style="cursor:pointer">{{ coating.description }}</div>

            <div class="ecard-meta">
                {% if coating.isTintable %}Колеруемое{% if coating.possibleColors|length > 0 %} · {{ coating.possibleColors|length }} цветов{% endif %}
                {% elseif coating.possibleColors|length > 0 %}{{ coating.possibleColors|length }} цветов{% endif %}
            </div>

            {% if coating.matchedSubstances is defined and coating.matchedSubstances|length > 0 %}
                {% set matches = coating.matchedSubstances %}
                <div class="ecard-badges">
                    <span class="b-ok-lbl">✓ Стойкое к:</span>
                    {% for m in matches|slice(0, 3) %}<span class="b-ok">{{ m.canonicalName }}</span>{% endfor %}
                    {% if matches|length > 3 %}<span class="b-ok">+{{ matches|length - 3 }}</span>{% endif %}
                </div>
            {% endif %}

            {% if coating.tags|length > 0 %}
                <div class="ecard-tags" data-controller="scroll-fade">
                    {% for tag in coating.tags %}
                        {% set isActive = tag.id in selectedTagIdList %}
                        {% set newTagIds = isActive ? selectedTagIdList|filter(id => id != tag.id) : selectedTagIdList|merge([tag.id]) %}
                        {% set q = app.request.query.all|merge({tagIds: newTagIds, page: null})|filter(v => v is not null) %}
                        <a href="{{ path('app_cabinet_coating_coating_list', q) }}"
                           class="tag{{ isActive ? ' is-active' : '' }}"
                           title="{{ isActive ? 'Убрать из фильтра' : 'Отфильтровать' }}">{{ tag.title }}{% if isActive %} ×{% endif %}</a>
                    {% endfor %}
                </div>
            {% endif %}
        </div>
    </div>
```

- [ ] **Step 2: Проверить маршрут дублирования.** Текущий `edit_delete.html.twig` передавал `duplicate` в `app_cabinet_coating_coating_create`. Подтвердить сигнатуру (параметр `duplicate` vs иное) в `components/edit_delete.html.twig` и в create-экшене; поправить путь в dropdown, если параметр называется иначе.
- [ ] **Step 3: Собрать.** `cd app && yarn dev`.
- [ ] **Step 4: Проверить визуально** (обе темы, мобайл+десктоп, залогинен): карточки покрытий в новом виде — монограм (EP/PUR/ЦС…) + полоса цветов, «N цветов», «Стойкое к» при поиске по веществу, теги-фильтры кликаются, kebab открывает Ред/Дублировать/Удалить, чекбокс добавляет в сравнение (трей появляется), клик по заголовку открывает превью. Infinite-scroll догружает такие же карточки.
- [ ] **Step 5: Коммит.** `git commit -m "Карточка покрытия — единый вид: монограм связующего + полоса цветов, «N цветов», kebab-действия, сохранены сравнение и превью"`

---

## Task 3: Карточка системы

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/cabinet/coating/coating_system/_list_cards.html.twig`

- [ ] **Step 1: Переверстать** в `.ecard` (компактно, вместо 2-колоночного блока). Медиа — иконка стопки слоёв (`.ecard-media` с `bi-layers`). Тело: заголовок (сохранить существующий превью-триггер системы + `data-system-id`); описание; мета — «{layers|length} слоёв · {totalDft} мкм» + мини-свотчи слоёв (по одному `colorHex` на слой, до ~5) + «{documentCount} докум.» (если >0); бейджи — соответствие: сгруппировать `compliance` по `standard`, вывести `label` как `.b-iso` (напр. «C5-H»); теги — `.ecard-tags` из `system.tags` (`.title`), без ссылок-фильтров (у систем свои фасеты; ссылки-фильтры по тегам системы — как в текущем шаблоне, если были). Действия — kebab (если canEdit) с Ред/Удалить (маршруты систем).

Ключевые поля/паттерн (форму брать из Task 2, менять медиа+мету+бейджи):
- мини-свотчи слоёв: `{% for l in system.layers|slice(0,5) %}{% if l.colorHex %}<span class="sw" style="background: {{ l.colorHex }}"></span>{% endif %}{% endfor %}`;
- соответствие: цикл по `complianceByStandard` (группировка уже есть в текущем шаблоне) → `<span class="b-iso">{{ entry.label }}</span>`;
- documentCount: `{% if system.documentCount > 0 %}{{ system.documentCount }} докум.{% endif %}`.
- Сохранить: триггер превью системы и `data-system-id` (взять из текущего шаблона — не потерять `data-action`/`data-*`).

- [ ] **Step 2: Собрать + визуальная проверка** (обе темы, мобайл+десктоп): карточки систем компактные, иконка слоёв, «N слоёв · totalDft мкм», свотчи слоёв, счётчик документов, бейджи соответствия (C5-H и т.п.), теги; превью системы открывается; kebab-действия работают.
- [ ] **Step 3: Коммит.** `git commit -m "Карточка системы — единый вид: иконка слоёв, свотчи слоёв, счётчик документов и бейджи соответствия ISO/СП"`

---

## Task 4: Карточка документа

**Files:**
- Modify: `app/src/Shared/Infrastructure/Templates/admin/certificate/document/_list_cards.html.twig`

- [ ] **Step 1: Переверстать** в `.ecard`. Медиа — иконка файла (`.ecard-media`, `bi-file-earmark-text`; при действующем сроке — `.is-ok`). Тело: заголовок (`title`/`subject`); описание (`description`); мета — статус (`expiresAt is null` → «Бессрочный»; `isExpired` → бейдж «Просрочен» danger; иначе «Действует до {{ expiresAt|date('m.Y') }}») + организация `issuerTitle ?? '—'`; бейдж типа `kindLabel` (`.b-iso` или нейтральный). Тегов у документа нет — не выводим. Действия — kebab (если canEdit), скачивание файла (если `hasFile`) — ссылкой/иконкой. Сохранить существующие триггеры/действия из текущего шаблона (превью документа, если есть).

Статус-бейдж:
```twig
{% if document.expiresAt is null %}<span class="b-iso">Бессрочный</span>
{% elseif document.isExpired %}<span class="b-ok" style="color:var(--danger-strong);background:var(--danger-subtle)">Просрочен · {{ document.expiresAt|date('m.Y') }}</span>
{% else %}<span class="b-ok">Действует до {{ document.expiresAt|date('m.Y') }}</span>{% endif %}
```

- [ ] **Step 2: Собрать + визуальная проверка**: карточки документов — иконка файла, статус (действует/просрочен/бессрочный), организация, тип; скачивание работает; kebab-действия.
- [ ] **Step 3: Коммит.** `git commit -m "Карточка документа — единый вид: иконка файла, статус срока действия, организация и тип"`

---

## Task 5: Трей сравнения — чипы с названиями + FAB

**Files:**
- Modify: `app/assets/controllers/compare_tray_controller.js`, `app/src/Shared/Infrastructure/Templates/admin/coating/coating/index.html.twig`
- Modify (add FAB): `_coating_cards_batch.html.twig` контекст — FAB создания на список (если canEdit).

- [ ] **Step 1: Хранить название вместе с id.** В `_coating_cards_batch` добавить на чекбокс `data-compare-title="{{ coating.title }}"`. В `compare_tray_controller.js` — при toggle сохранять `{id, title}` (в localStorage массив объектов; миграция старого формата массива строк — если элемент строка, обернуть). `_sync()` рендерит чипы: для каждого выбранного — чип «{title} ×», крестик убирает (снимает соответствующий чекбокс + из хранилища). Кнопка «Сравнить» — как прежде (open → compareUrl?ids=csv, ≥2).
- [ ] **Step 2: Разметка трея** в `index.html.twig` — заменить счётчик на контейнер чипов (target `chips`) в стиле макета (removable-чипы), стилизовать на токенах (можно классы из entity-card.css или новые в app-shell/entity-card). Заголовок «Сравнение · N из 4», кнопка «Сравнить».
- [ ] **Step 3: FAB создания** (мобайл): добавить на страницу списка (в `index.html.twig`, вне карточек) `{% if canEdit %}<a class="fab-create" href="{{ path('app_cabinet_coating_coating_create') }}" aria-label="Новое покрытие"><i class="bi bi-plus"></i></a>{% endif %}` (на десктопе скрыт — есть кнопка в шапке `list_page`).
- [ ] **Step 4: Собрать + проверка**: выбор карточек → трей показывает чипы-названия, крестик убирает, «Сравнить» открывает сравнение; лимит 4; межвкладочная синхронизация (localStorage) не сломана; FAB на мобиле создаёт покрытие.
- [ ] **Step 5: Коммит.** `git commit -m "Трей сравнения — removable-чипы с названиями вместо счётчика; FAB создания на мобиле"`

---

## Task 6: Финальная верификация

- [ ] **Step 1: Сборка.** `cd app && yarn dev` — без ошибок.
- [ ] **Step 2: Свип** (обе темы, мобайл+десктоп): три списка (покрытия/системы/документы) — единый вид карточки, различаются медиа/метой; фасеты/фильтры-шторки работают как раньше (не задеты); сравнение (чекбокс→трей→Сравнить) работает; превью открывается; infinite-scroll ок; FAB на мобиле.
- [ ] **Step 3: Грепы контрактов.** `rtk grep -n "data-compare-id\|coating-preview-loader\|data-system-id" src/Shared/Infrastructure/Templates` — контракты на месте в новых карточках.

## Self-review (покрытие спеки, разделы 5, 6.2)

- Единая карточка (медиа+тело+мета+бейджи+теги+kebab) → Task 1 (CSS) + Task 2/3/4 (три сущности). Медиа-слот адаптивный: покрытие — монограм+полоса (Task 2), система — иконка слоёв+свотчи (Task 3), документ — иконка файла+статус (Task 4). Трей чипами + FAB → Task 5. Фасеты — не тронуты (контракт сохранён).
- Данных в DTO достаточно (разведка) — бэкенд не трогаем; у документов нет тегов (осознанно опущены).

## Открытые точки (решить в начале Task 2)

- **Монограм связующего — РЕШЕНО:** ISO-код из `coating.base` как есть (`EP/PUR/AK/AY/ESI/PAS/PS/FEVE`), без ремапа. `isZincRich` → маленький бейдж «Zn» на плитке (отдельный индикатор, не меняет монограм). Бэкенд не трогаем.
- **Система: бинарная метка ISO 12944.** Показываем `compliance[].label` бейджами (как есть данные). Если нужен именно значок «ISO 12944 ✓» — уточнить, какие коды лежат в `compliance[].standard`, и фильтровать по ним (без бэкенда) либо добавить флаг в DTO (тогда отдельная под-задача).
- **Kebab-удаление формой.** Проверить CSRF/метод удаления покрытия (текущий `edit_delete.html.twig` — как отправляет delete: POST+_method? confirm-modal?). Привести dropdown-удаление к тому же контракту (Task 2 Step 2), не изобретая свой.
