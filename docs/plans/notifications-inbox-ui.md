# Деплой 2: раздел «Уведомления» (инбокс-UI)

Сосед: `docs/plans/notifications-unread-badge.md` (Деплой 1 — бэк + бейдж). **Требует Деплой 1** (сущность `Notification`, репозиторий, `SendNotification`, счётчик unread уже есть). Этот план самодостаточен по своему объёму.

## Зачем

Деплой 1 дал хранение уведомлений и правильный бейдж, но **посмотреть уведомления негде** — пуш-баннер ОС эфемерный, а в БД строки лежат «вслепую». Этот деплой добавляет раздел кабинета: колокол со счётчиком непрочитанного + список уведомлений с отметкой «прочитано». Тогда бейдж сбрасывается осознанно (зашёл в раздел → прочитал), а пропущенные баннеры видно в приложении.

## Ключевые решения (развилки)

1. **Где показывать:** отдельная страница `/cabinet/notifications` (список) + иконка-колокол со счётчиком в шелле (`base.html.twig`), ведёт на страницу. Дропдаун-превью в шапке — можно, но начинаем со страницы (проще, копируем существующий list-паттерн). Развилка: страница сейчас, дропдаун — если попросят.
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
- Шаблоны `app/src/.../Templates/cabinet/notifications/` — копия структуры ближайшего list-шаблона кабинета: строка/карточка уведомления (текст `message`, относительное время `createdAt`, непрочитанное выделено оттенком без новых классов), пагинация. **Ссылки внутри `message`** делаем кликабельными безопасным autolink'ом: Twig экранирует текст, отдельным фильтром оживляем только `http(s)`-URL (тексты авторские, не пользовательский ввод). Без отдельного `url`-поля.
- services.yaml — блок `App\Notifications\Infrastructure\Controller\` уже добавлен в Деплое 1 (MarkReadAction); ListAction подхватится тем же ресурсом.

## Шелл — колокол со счётчиком

`base.html.twig` (в шапке/нижней навигации, где уместно по существующей разметке): иконка `bi-bell` со ссылкой на `app_notifications_list`; счётчик непрочитанного — badge-оверлей (копируем существующий приём, если есть; иначе минимальный span). Число — из `CountUnreadNotificationsQuery`, прокинутое как twig-global или через мелкий view-хелпер (не тянуть запрос в шаблон напрямую — считать в контроллере/подписчике и передавать). Развилка: как прокинуть число в шелл на всех страницах — **twig-global через отдельный provider** (аналогично `vapid_public_key`), считающий unread для авторизованного; либо ViewComponent. Определиться на этапе реализации, глядя на существующие глобалы.

## Фронт (sw.js)

Теперь, когда раздел существует, клик по пуш-баннеру ведёт в него: в `notificationclick` (`app/public/sw.js`) цель — `/cabinet/notifications` (в Деплое 1 клик открывал приложение, т.к. раздела ещё не было). Проще всего — фиксировано открывать `/cabinet/notifications` (пер-уведомление `url` нет). Бамп версии SW не требуется (обновляется по diff).

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
