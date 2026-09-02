# Security Fix 2 — CSRF / GET-мутации (глобальный фикс)

Статус: план готов, реализация не начата.
Ветка: `security/fix-2-csrf-get-mutations` (от `main`).
Соседние планы: [security-fix-1-tenant-isolation.md](security-fix-1-tenant-isolation.md), [security-fix-3-hardening.md](security-fix-3-hardening.md).

## Контекст

Целый класс destructive-роутов доступен по GET (нет ограничения `methods`) и вызывается из `<a href>`.
Серверной CSRF-валидации нет нигде, кроме логина (`LoginFormAuthenticator.php:41`, intention `'authenticate'`).
`_csrf_token` в шаблонах декоративный — его никто не проверяет. `session.cookie_samesite: 'lax'`
(`framework.yaml:16`) **не** защищает top-level GET-навигацию → заманил жертву на ссылку/`<img>`/prefetch —
мутация выполнилась под её сессией (`always_remember_me: true` держит юзера залогиненным).

Стек: Symfony 7.0, `symfony/security-csrf ^7.0` установлен, `CsrfTokenManagerInterface` уже автовайрится
(alias `security.csrf.token_manager`; `form.csrf_protection.enabled: true` в `framework.yaml:5-7` уже бутит подсистему).
Только два подписчика в проекте (`ChannelVerificationGate`, `ConsoleAuthenticationSubscriber`), оба автоконфигурятся —
новый подписчик в `services.yaml` регистрировать не нужно (`config/services.yaml:18-20` резолвит весь `src/`).

**Подход (выбран пользователем): глобально** — kernel-подписчик, запрещающий небезопасные методы без валидного
CSRF-токена на cookie-firewall, + перевод всех destructive-роутов на POST + общий партиал кнопки-формы.

## Что закрываем
13 подтверждённых находок класса «GET-мутация / нет серверного CSRF» (см. таблицу ниже).
Два HIGH: удаление заявки (жертва — любой юзер) и удаление производителя (жертва — админ). Остальные —
удаления под админом (medium, нужен клик админа) + clone + create-channel-из-GET.

---

## 1. Глобальный подписчик `CsrfRequestSubscriber`

Файл: `src/Shared/Infrastructure/EventListener/Request/CsrfRequestSubscriber.php` (рядом с `SessionRequestProcessor`).
Слушает `kernel.request` (после роутинга, до контроллера), стиль — как `ChannelVerificationGate` (`isMainRequest()`).
Отказ — существующий `ForbiddenException` (его ловит `ForbiddenExceptionListener` → 403 HTML/JSON, как везде в приложении).

```php
<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Request;

use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CsrfRequestSubscriber implements EventSubscriberInterface
{
    /** Единый intention для всех «мутационных» форм приложения. */
    public const INTENTION = 'mutation';

    /** Роуты со своей CSRF-защитой (Symfony Form / LoginFormAuthenticator) — не трогаем. */
    private const EXEMPT_ROUTES = [
        'app_login',                          // authenticator, intention 'authenticate'
        'app_cabinet_coating_manufacturer_create',
        'app_cabinet_coating_manufacturer_update',
        'app_user_channel_create',            // CreateChannelFormType (form.csrf_protection)
        // + прочие POST на Symfony Form: create|update для coating/system/document/issuer/substance
    ];

    public function __construct(private CsrfTokenManagerInterface $csrf) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 9]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();

        if ($request->isMethodSafe()) {                          // GET/HEAD/OPTIONS/TRACE
            return;
        }
        if (str_starts_with($request->getPathInfo(), '/api')) {  // stateless JWT firewall — CSRF N/A
            return;
        }
        if (\in_array($request->attributes->get('_route'), self::EXEMPT_ROUTES, true)) {
            return;
        }

        $token = $request->headers->get('X-CSRF-TOKEN') ?? $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->csrf->isTokenValid(new CsrfToken(self::INTENTION, $token))) {
            throw new ForbiddenException('Недействительный CSRF-токен.');
        }
    }
}
```

- Intention **серверный, фиксированный** (`'mutation'`) — клиент не выбирает. Формы шлют `csrf_token('mutation')`
  в hidden `_csrf_token` (или заголовок `X-CSRF-TOKEN` для `fetch`). Аналог уже работает в `_login_form.html.twig:21`.
- JWT-firewall (`^/api...`) исключён по префиксу пути — там нет cookie, CSRF неприменим.
- **Развилка (важно): исключать Symfony-Form роуты.** Create/update coating/system/document/issuer/substance/manufacturer/channel
  и `app_login` уже валидируют токен через `form.csrf_protection` / authenticator, и они не GET-доступны — это **не**
  наш класс дыр. Гейтить их одним intention `'mutation'` = двойная валидация против чужого id → 403 на легитимных сабмитах.
  Поэтому `EXEMPT_ROUTES` (или: пропускать, если запрос уже несёт form-токен). Это же держит test-churn узким.

Регистрация: не требуется (автоконфигурация).

---

## 2+3. Роуты: GET → POST (таблица)

| # | Роут | Контроллер (`#[Route]`) | Сейчас | Стало | Триггер в шаблоне |
|---|---|---|---|---|---|
| 1 | `..._proposals_general_proposal_delete` | `Proposals/.../DeleteAction.php:15` | GET | `methods: ['POST']` | `cabinet/proposal/index.html.twig:47-54` → `edit_delete.html.twig` → `#deleteModal` |
| 2 | `..._proposals_general_proposal_item_delete` | `Proposals/.../RemoveItemAction.php:15` | GET | `['POST']` | `twig/components/GeneralProposalInfoItem.html.twig:90` — голый `<a>` GET, без модалки |
| 3 | `..._proposals_general_proposal_clone` | `Proposals/.../CloneAction.php:17` | GET | `['POST']` | `cabinet/proposal/index.html.twig:34` — голый `<a>` GET |
| 4 | `..._coating_coating_delete` | `Coatings/.../Coating/DeleteAction.php:14` | GET | `['POST']` | `admin/coating/coating/_coating_cards_batch.html.twig:55-60` (dropdown `<a>`+модалка); модалка `.../index.html.twig:472` |
| 5 | `..._coating_system_remove` | `Coatings/.../CoatingSystem/RemoveAction.php:14` | GET | `['POST']` | `cabinet/coating/coating_system/_list_cards.html.twig:51-56`; модалка `.../list.html.twig:543` |
| 6 | `..._surface_treatment_remove` | `Coatings/.../SurfaceTreatment/RemoveAction.php:14` | GET | `['POST']` | `cabinet/coating/surface_treatment/list.html.twig:68-74` → `edit_delete.html.twig`; модалка `:119` |
| 7 | `..._coating_manufacturer_delete` | `Coatings/.../Manufacturer/ManufacturerController.php:100` | GET | `['POST']` | `admin/coating/manufacturer/index.html.twig:33-39` → `edit_delete.html.twig`; модалка `:78` |
| 8 | `..._certificate_document_delete` | `Certificates/.../Document/DeleteAction.php:13` | GET | `['POST']` | `admin/certificate/document/_list_cards.html.twig:45-50`; модалка `.../index.html.twig:177` |
| 9 | `..._certificate_issuer_delete` | `Certificates/.../Issuer/DeleteAction.php:13` | GET | `['POST']` | `admin/certificate/issuer/index.html.twig:33-39` → `edit_delete.html.twig`; модалка `:47` |
| 10 | `..._chemical_resistance_substance_delete` | `ChemicalResistance/.../Substance/DeleteAction.php:14` | GET | `['POST']` | `admin/chemical_resistance/substance/index.html.twig:59-65` → `edit_delete.html.twig`; модалка `:73` |
| 11 | `..._by_substance_assessment_delete` | `ChemicalResistance/.../Assessment/DeleteFromSubstanceAction.php:18` | GET+POST | `['POST']` (убрать GET) | `cabinet/chemical_resistance/_resistant_cards.html.twig:111-116` — `<button data-bs-url>`+модалка; модалка `.../by_substance.html.twig:197` |
| 12 | `app_user_channel_create` | `Users/.../Channel/CreateChannelAction.php:22` | GET+POST (создаёт из query!) | `['GET','POST']`, **убрать GET-ветку авто-создания** (§4) | форма `user/channel/create.html.twig:17-20` (уже POST) |

Заметка по #11: URL удаления несёт `?substanceIds[]=…` (строится в `_resistant_cards.html.twig:114` через
`filterQuery|merge`), т.к. `RedirectsToBySubstanceTrait::redirectToBySubstance` (`.../RedirectsToBySubstanceTrait.php:17-30`)
восстанавливает фильтр из `$request->query` на редиректе. Query переживает POST → `action` POST-формы должен сохранить
query-строку (или нести `substanceIds[]` скрытыми полями).

---

## 3. Общий партиал формы-удаления

### Что есть сейчас
- `components/edit_delete.html.twig:15-23` рендерит удаление как `<a href="{{ path(delete,{id}) }}" data-bs-toggle="modal" data-bs-target="#deleteModal">` (href = no-JS GET-фолбэк).
- `components/delete_modal.html.twig` — одна на страницу модалка; confirm — `<a data-role="confirm">`, href ставит JS из `data-bs-url` / `data-bs-id`+route (`:22`, `:42-48`). В шапке комментарий «Ссылка удаления — GET (как во всём приложении)» (`:11`).
- Включена на 9 страницах со списками (`{% include 'components/delete_modal.html.twig' %}`).
- Переиспользуемого POST/CSRF-партиала **нет** — создаём.

### Новый `components/_delete_form.html.twig`
Настоящая POST-форма (работает без JS) + hidden `_csrf_token` (`mutation`) + danger-кнопка с текущими классами
(без новых стилей — правило проекта). Принимает `url` (кейс с query) ИЛИ `route`+`id`.

```twig
{# POST-форма удаления: без JS сабмитит сразу, с JS — открывает #deleteModal.
   Параметры: url ИЛИ (route + id); title; label (по умолч. «Удалить»); btnClass. #}
{% set action = url is defined ? url : path(route, {'id': id}) %}
<form method="post" action="{{ action }}" class="d-inline delete-form">
    <input type="hidden" name="_csrf_token" value="{{ csrf_token('mutation') }}">
    <button type="submit"
            class="{{ btnClass|default('btn btn-sm btn-soft-danger') }}"
            data-bs-toggle="modal" data-bs-target="#deleteModal"
            data-bs-title="{{ title|default('') }}">
        <i class="bi bi-trash3"></i><span class="d-none d-md-inline ms-1">{{ label|default('Удалить') }}</span>
    </button>
</form>
```

### Переделка `delete_modal.html.twig` (JS: submit вместо navigate)
Confirm перестаёт быть `<a href>` и ре-сабмитит форму, которая открыла модалку, через `requestSubmit()`
(память проекта: `form.submit()` обходит событие `submit` chip-facets — всегда `requestSubmit()`):

```twig
<button type="button" class="btn btn-danger flex-fill" data-role="confirm">
    <i class="bi bi-trash3 me-1"></i>Удалить
</button>
{# JS: на show запомнить event.relatedTarget.closest('form');
   клик confirm → targetForm.requestSubmit(); title из data-bs-title. #}
```

Без JS: клик по danger-кнопке сабмитит свою POST-форму сразу (токен приложен) → сервер удаляет.
С JS: `data-bs-toggle="modal"` открывает confirm, confirm зовёт `requestSubmit()`. Инвариант «формы работают без JS» сохранён.

### Шаблоны, которые переводим на партиал
- Через `edit_delete.html.twig` (5): заменить `<a>` удаления (`edit_delete.html.twig:15-23`) на include `_delete_form.html.twig` (route+id):
  `surface_treatment/list.html.twig:68`, `proposal/index.html.twig:47`, `admin/coating/manufacturer/index.html.twig:33`,
  `admin/certificate/issuer/index.html.twig:33`, `admin/chemical_resistance/substance/index.html.twig:59`.
- Dropdown `<a>` (3) — конвертировать в POST-форму/кнопку (сохранить вид `dropdown-item text-danger` через `btnClass`):
  `coating_system/_list_cards.html.twig:51`, `coating/_coating_cards_batch.html.twig:55`, `certificate/document/_list_cards.html.twig:45`.
- `<button data-bs-url>` (1) — `chemical_resistance/_resistant_cards.html.twig:111` → include с `url` = текущий `data-bs-url`+query (`:114`).
- Голые GET `<a>` без модалки (2): `GeneralProposalInfoItem.html.twig:90` (item delete) и `cabinet/proposal/index.html.twig:34`
  (clone) → инлайн POST-форма. Clone — тот же партиал с `label:'Дублировать'`, `btnClass:'btn btn-outline-secondary'`,
  либо отдельный `_post_action.html.twig` (clone не удаление).
- 9 страниц с `{% include 'components/delete_modal.html.twig' %}` — include остаётся, меняются только внутренности модалки.

---

## 4. `CreateChannelAction` (роут #12)

- Роут `:22` `methods: ['GET','POST']`; firewall — `main` cookie (нет отдельного `^/user` firewall, только access_control `^/user IS_AUTHENTICATED`). Подписчик его покрывает, но роут в `EXEMPT_ROUTES` (POST через `CreateChannelFormType`, form-CSRF).
- Дыра: `:42-43` читает `type`/`value` из `$request->query`; `:57` ветка `if (!empty($formData) && $type && $value && !$request->isMethod('POST'))` **создаёт канал на GET** («минуя CSRF», `:56,:82`, → `createChannelFromFormData` `:85`). `GET /user/channel/create?type=email&value=attacker@x` тихо создаёт канал на аккаунте жертвы (CSRF через `<img>`).
- Изменение: **удалить `:41-95`** (всю query-ветку авто-создания + ручные валидаторы). Оставить: рендер формы на GET и POST-путь `handleRequest` + `isSubmitted()/isValid()` (`:97-105`, form-CSRF). `createChannelFromFormData`/`handleChannelCreationException` остаются. Шаблон уже POST (`create.html.twig:17-20`). `methods` оставить `['GET','POST']` (GET рендерит пустую форму).

---

## 5. CSRF-конфиг
- `framework.yaml:5-7` `form.csrf_protection.enabled: true` уже бутит подсистему → `CsrfTokenManagerInterface` доступен, `csrf_token()` работает. **Ничего строго добавлять не нужно.**
- `:4` `#csrf_protection: true` — закомментировано. Рекомендуется раскомментировать (`framework.csrf_protection: true`) ради явности; функционально не обязателно.
- `when@test` (`:39-43`): **CSRF в тестах не отключать** — тест «tokenless POST отклонён» требует включённого CSRF; session-bound токены работают на `mock_file` storage (KernelBrowser переиспользует сессию).

---

## 6. Тесты

### Существующие тесты, которые сломаются (зеркальные пути)
| Тест | Метод | Кейс | Ломается | Правка |
|---|---|---|---|---|
| `tests/Functional/Certificates/.../Document/DocumentControllerTest.php:144-153` | GET `/…/delete` | delete+redirect | route→POST ⇒ 405 | POST + `_csrf_token` |
| там же `:208` | POST delete (без токена) | non-admin | теперь 403-CSRF до auth-гейта | добавить токен |
| `tests/Functional/Certificates/.../Issuer/IssuerControllerTest.php:138-148` | GET delete | delete+redirect | 405 | POST + токен |
| `tests/Functional/Coatings/.../CoatingSystem/RemoveActionTest.php:96` | POST (без токена) | remove+redirect | 403-CSRF | добавить токен |
| `tests/Functional/Coatings/.../SurfaceTreatment/RemoveActionTest.php:107,124` | POST (без токена) | admin/non-admin | admin ⇒ 403-CSRF | токен в оба |
| `tests/Functional/ChemicalResistance/.../Assessment/BySubstanceAssessmentActionsTest.php:211,232,248,266` | POST (без токена) | add/update/delete/non-admin | 403-CSRF | токен в каждый мутирующий POST |

Create/update-тесты на Symfony-Form роутах (`DocumentControllerTest:128`, `IssuerControllerTest:111,129`) сломаются
**только если** эти роуты не в `EXEMPT_ROUTES`. Исключение form-роутов держит их зелёными без правок — сильный
аргумент за exempt-list.

### Как тест берёт токен (CSRF включён в тестах)
```php
$crawler = $this->client->request('GET', '/cabinet/certificate/issuer');
$token = $crawler->filter('form.delete-form input[name="_csrf_token"]')->first()->attr('value');
$this->client->request('POST', "/cabinet/certificate/issuer/$id/delete", ['_csrf_token' => $token]);
```
Альтернатива: `static::getContainer()->get('security.csrf.token_manager')->getToken('mutation')->getValue()`.

### Новый тест — tokenless POST отклонён
`tests/Functional/Shared/Infrastructure/EventListener/Request/CsrfRequestSubscriberTest.php`: залогиненный админ,
`POST /cabinet/certificate/issuer/{id}/delete` **без** `_csrf_token` ⇒ 403 и сущность жива. Позитивный кейс с
валидным `mutation`-токеном ⇒ редирект + удаление.

---

## Флаги no-JS (формы обязаны работать без JS)
- Confirm-модалка сейчас JS-only. В новом партиале триггер — настоящая submit-кнопка в POST-форме: **без JS сабмитит сразу** (без confirm-диалога, но мутация работает и CSRF-safe); JS накладывает confirm сверху. Токен/action **не** прятать в JS-only код, иначе no-JS удаление умрёт.
- Роуты #2 (item delete) и #3 (clone) — сейчас голые `<a>` GET без модалки; перевод на инлайн POST-формы сохраняет работу без JS.
- Роут #11 (assessment delete) — держать `?substanceIds[]=…` в `action` POST-формы (или скрытыми полями), иначе редирект после удаления теряет фильтр.
- `when@test`: CSRF не отключать.

## Верификация волны
```bash
cd app && yarn dev   # после правок Twig/JS
cd app && vendor/bin/phpunit tests/Functional   # в контейнере
```
Ручная проверка: no-JS удаление (JS выключен) сабмитит и удаляет; с JS — confirm-модалка → requestSubmit → удаление; tokenless POST → 403.

## Порядок реализации (по шагам)
1. `CsrfRequestSubscriber` + `EXEMPT_ROUTES` + новый тест (tokenless POST → 403). Показать.
2. Партиал `_delete_form.html.twig` + переделка `delete_modal.html.twig` (JS requestSubmit). Показать на одной странице.
3. Перевод роутов на POST + шаблоны, партиями по контекстам (Proposals → Coatings → Certificates → ChemicalResistance). Чинить сломавшиеся тесты той же партией.
4. `CreateChannelAction` — выпил GET-ветки. Показать.
