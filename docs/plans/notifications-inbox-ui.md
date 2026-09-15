# Деплой 2: раздел «Уведомления» (инбокс-UI)

Сосед: `docs/plans/notifications-unread-badge.md` (Деплой 1 — бэк + бейдж). **Требует Деплой 1** (сущность `Notification`, репозиторий, `SendNotification`, счётчик unread уже есть). Этот план самодостаточен по своему объёму.

## Зачем

Деплой 1 дал хранение уведомлений и правильный бейдж, но **посмотреть уведомления негде** — пуш-баннер ОС эфемерный, а в БД строки лежат «вслепую». Этот деплой добавляет раздел кабинета: колокол со счётчиком непрочитанного + список уведомлений с отметкой «прочитано». Тогда бейдж сбрасывается осознанно (зашёл в раздел → прочитал), а пропущенные баннеры видно в приложении.

## Ключевые решения (развилки) — согласовано на макете

1. **Уведомления — часть «Профиля», не доменной навигации.** Личное вынесено в хаб **Профиль** (`/cabinet/profile`): шапка аккаунта (email) + **чистое меню** — сейчас один пункт «Уведомления» (со счётчиком, → своя страница `/cabinet/notifications`). Фейковых «скоро» не рисуем; меню-структура сама расширяема (позже — контакты, смена пароля → новая строка). **Размещение навигации:** десктоп — пункт «Профиль» (`bi-person-circle`) в аккаунт-зоне подвала сайдбара со счётчиком; мобилка — «Профиль» в шторке «Ещё» + **колокол `bi-bell` со счётчиком в мобильной шапке** ведёт сразу на `/cabinet/notifications`. Вкладки таб-бара остаются только каталогом (домен).
2. **Push-тумблер — настройка самого раздела уведомлений, не пункт профиля.** Других видов уведомлений в приложении нет, поэтому вкл/выкл push — это настройка раздела. Живёт в блоке «Настройки» на странице `/cabinet/notifications` (переиспользуем `_notifications_button.html.twig`), переезжает из шторки «Ещё». Блок «Настройки» можно развивать (будущие настройки уведомлений).
2. **Разметку не изобретаем** — копируем ближайший аналог списка кабинета (`Coatings/.../ListAction` + его Twig `index.html.twig`/`_batch.html.twig`, пагинация как есть). Без новых CSS-классов/цветов, иконки — `bi-*`.
3. **Отметка «прочитано»:** заход на страницу списка = пометить всё прочитанным (`MarkNotificationsRead`, уже есть из Деплоя 1) → бейдж/счётчик гаснут. Пер-элементная отметка — по желанию позже. Развилка: **mark-all-on-open** (просто, консистентно с бейджем).
4. **Счётчик в колоколе** — из `CountUnreadNotificationsQuery` (read-side), рендерится в шелле для авторизованного. Обновление живьём без перезагрузки — не делаем (YAGNI); число актуально на рендере страницы.

## Read-side — `app/src/Notifications/Application/UseCase/Query/`

Зеркаль `Coatings/.../GetPagedCoatings/*` + `Users/.../FindChannel/*`.

- `Domain/Repository/NotificationFilter.php` — `(string $ownerUlid, ?bool $isRead, ?Pager $pager)`.
- `NotificationRepositoryInterface::findByFilter(NotificationFilter): PaginationResult` (+ реализация: QB по `owner_id`, сортировка `created_at DESC`, `Paginator` → `PaginationResult`).
- `GetUserNotifications/{Query, Handler, Result}` — handler зовёт `findByFilter`, гоняет `NotificationDTOTransformer::fromEntityList`, строит `Pager(page, perPage, total)`, отдаёт `Result(dtos, pager)`.
- `CountUnreadNotifications/{Query, Handler, Result}` — `countUnread(ownerUlid)` → число (для колокола).
- `Application/DTO/Notification/{NotificationDTO, NotificationDTOTransformer}` — плоский DTO (`id, message, createdAt, isRead`).

## Инфраструктура — контроллер + шаблоны

- `Notifications/Infrastructure/Controller/ListAction.php` — `#[Route('/cabinet/notifications', name: 'app_notifications_list', methods: ['GET'])]`:
  1. `ownerUlid` из `AuthUserFetcherInterface`;
  2. `queryBus->execute(new GetUserNotificationsQuery(...))` → список;
  3. `commandBus->execute(new MarkNotificationsReadCommand($ownerUlid))` — заход = прочитано (бейдж погаснет со следующим рендером/пушем);
  4. `render('cabinet/notifications/index.html.twig', [...])`. Partial-режим (`?partial`) для пагинации — как в `Coatings/ListAction`, если нужен.
- Шаблоны `app/src/.../Templates/cabinet/notifications/` — копия структуры ближайшего list-шаблона кабинета: строка/карточка уведомления (текст `message`, относительное время `createdAt` через `|timeAgo`, непрочитанное выделено оттенком без новых классов), пагинация. Без отдельного `url`-поля.
- **БЕЗОПАСНОСТЬ autolink (проверить при реализации) — «а если в уведомление записать ссылку на вредоносный сайт?»:**
  1. **XSS:** текст всегда экранируем (Twig autoescape); оживляем ссылки ТОЛЬКО отдельным фильтром, который сперва `e()`-экранирует, потом оборачивает совпадения в `<a>`. Никакого `|raw` на сыром `message`.
  2. **Схемы:** линкуем только `http(s)://` — никаких `javascript:`/`data:`/`vbscript:`. Регэксп строго на `https?://`.
  3. **Кликджек/утечка referrer:** на `<a>` вешаем `rel="noopener noreferrer nofollow"` (и `target="_blank"` по вкусу).
  4. **Фишинг (главное):** по возможности линкуем **только свой origin** (`1helper.ru` / относительные `/cabinet/...`), а внешние URL оставляем **простым текстом** (не кликабельны). Это и безопаснее, и совпадает с идеей «клик ведёт вглубь приложения».
  5. **Источник:** сейчас `message` пишет только наш код (SendNotification, будущие триггеры) — не пользовательский ввод. Инвариант: если появится путь, где текст уведомления формируется из пользовательских данных — вернуться к пунктам 1-4 обязательно (сейчас — задокументировать, что источник доверенный).
- services.yaml — блок `App\Notifications\Infrastructure\Controller\` уже добавлен в Деплое 1 (MarkReadAction); ListAction подхватится тем же ресурсом.

## Шелл — колокол со счётчиком

`base.html.twig` (в шапке/нижней навигации, где уместно по существующей разметке): иконка `bi-bell` со ссылкой на `app_notifications_list`; счётчик непрочитанного — badge-оверлей (копируем существующий приём, если есть; иначе минимальный span). Число — из `CountUnreadNotificationsQuery`, прокинутое как twig-global или через мелкий view-хелпер (не тянуть запрос в шаблон напрямую — считать в контроллере/подписчике и передавать). Развилка: как прокинуть число в шелл на всех страницах — **twig-global через отдельный provider** (аналогично `vapid_public_key`), считающий unread для авторизованного; либо ViewComponent. Определиться на этапе реализации, глядя на существующие глобалы.

## Фронт (sw.js)

Теперь, когда раздел существует, клик по пуш-баннеру ведёт в него: в `notificationclick` (`app/public/sw.js`) цель — `/cabinet/notifications` (в Деплое 1 клик открывал приложение, т.к. раздела ещё не было). Проще всего — фиксировано открывать `/cabinet/notifications` (пер-уведомление `url` нет). Бамп версии SW не требуется (обновляется по diff).

## Реальная вёрстка — переиспользуем существующее (не изобретать)

Свериться с кодом перед версткой (макет-артефакт был эскизом, часть стилей в нём выдумана — `.mrow`/`.avatar` в приложении НЕТ):
- **Профиль-хаб — НОВАЯ страница `/cabinet/profile`** (новый `ProfileController`). Существующий `/cabinet` (`app_cabinet`, `cabinet/index.html.twig`) — это ОБЗОР возможностей, не профиль; его не переделываем.
- **Вход в профиль уже есть — переиспользуем, не плодим пункт:** десктоп — пункт аккаунта в `_shell/_sidebar.html.twig` (`app-sidebar-foot`: `<a href="{{ path('app_cabinet') }}"><i class="bi bi-person-circle"></i><span>{{ app.user.email }}</span></a>`) → перенаправить на `app_cabinet_profile` + повесить бейдж-счётчик. Мобилка — аккаунт-блок в offcanvas `#mainMenu` (base.html.twig) + колокол `bi-bell` со счётчиком в мобильной шапке `.blog-header-bar` (base.html.twig:90-98) → на `/cabinet/notifications`. Таб-бар (`_bottom_nav`) и сайдбар-nav (`nav_items`) — только домен, не трогаем.
- **Кирпичи (все существуют, нового CSS не добавляем):** страница — `{% extends 'base.html.twig' %}` + `main.container-fluid.py-3`; хедер — плашка `p-3 mb-3 rounded-3 bg-body-tertiary` с `h1.h3` + email (как `cabinet/index.html.twig:33-41`). Меню профиля — `list-group list-group-flush` в тертиарной плашке (как аккаунт-список в `#mainMenu`) ЛИБО `.ecard`. Строка уведомления — `.ecard` (медиа `bi-bell` + текст + `.ecard-meta` время) или тертиарная плашка; непрочитанное — оттенком `accent-subtle` + точка (существующими утилитами). Пустое — `{% include 'components/empty.html.twig' %}` (иконка `bi-bell`). Время — `|timeAgo`. Бейдж-счётчик — паттерн `document/index.html.twig` (`position-relative` + `span.position-absolute.top-0.start-100.translate-middle.badge.rounded-pill`). Push-тумблер — `_notifications_button.html.twig` (перенести из «Ещё»). Кнопки — `btn-sm btn-outline-primary` / `btn-outline-secondary`.
- Маршруты: `app_cabinet_profile` (`/cabinet/profile`, GET), список — `app_notifications_list` (`/cabinet/notifications`, GET), mark-read — уже есть (`/cabinet/notifications/read`).

## Тесты

- Functional `GetUserNotificationsQueryHandler` (реальная БД): пагинация, сортировка DESC, фильтр `isRead`.
- Functional `ListAction`: заход помечает прочитанным (после GET `countUnread`=0), аноним — редирект/401.
- Unit `NotificationDTOTransformer` — round-trip полей.
- Twig — визуально; PHP-тесты фронт не трогают.

## Порядок реализации (по шагам, апрув после каждого)

1. Read-side: `NotificationFilter` + `findByFilter` + `GetUserNotifications` (Query/Handler/Result) + DTO/Transformer + functional-тест.
2. `CountUnreadNotifications` Query/Handler (для колокола).
3. `ListAction` (+ mark-read on open) + функциональный тест.
4. Шаблоны `cabinet/notifications/*` (копия аналога) + `yarn dev`.
5. Колокол со счётчиком в `base.html.twig` (+ провайдер числа).
6. `./run check` зелёный; ручная проверка (список, счётчик, сброс при заходе).

## Верификация

Пуш → в колоколе «1» → зашёл в `/cabinet/notifications` → уведомление в списке, счётчик и бейдж PWA сброшены → в БД `is_read=true`. На втором устройстве бейдж подтянется со следующим пушем (см. Деплой 1).
