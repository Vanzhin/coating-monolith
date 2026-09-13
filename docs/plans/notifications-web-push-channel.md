# Деплой: канал Web Push

Добавляет `WEB_PUSH` как ещё один канал в существующую архитектуру нотифайеров (см.
`notifications-multichannel-design.md`). Контракт `NotifierInterface` не меняем. По завершении —
полный набор доставки: email + telegram + web push.

## Зависимости / конфиг
- `composer require minishlink/web-push` (VAPID-подпись + шифрование payload).
- VAPID-ключи один раз (`VAPID::createVapidKeys()`), в secrets/env: `VAPID_PUBLIC_KEY`,
  `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (mailto: или URL). Приватный — в Symfony secrets.
- Public-ключ отдать фронту (route `GET /push/vapid-key` или meta/`data-` в шаблоне для залогиненных).

## Модель канала
- `ChannelType::WEB_PUSH = 'web_push'` (`Users/Domain/Entity/ChannelType`).
- Подписка браузера = один `Channel` типа WEB_PUSH, `value` = JSON `{endpoint, keys:{p256dh, auth}}`,
  создаётся сразу `verified` (согласие даёт браузер, OTP не шлём). Несколько устройств → несколько
  WEB_PUSH-каналов у юзера (это ок, фан-аут по устройствам сам собой).
- **Проверить при реализации**: колонка `value` в persistence `Channel` достаточной длины под JSON
  подписки (endpoint длинный) — при нужде миграция на `text`. Как создаётся `Channel` (есть ли
  репозиторий/команда) — переиспользовать, добавив «создать verified без OTP».

## Доставка
- `Shared/Infrastructure/Service/WebPushNotifier implements NotifierInterface`:
  - `isSupportedChannel` → `WEB_PUSH`.
  - `notify(Channel, message)` — декодит `value` в `Minishlink\WebPush\Subscription`, шлёт payload
    JSON `{title, body, url}` через `WebPush::sendOneNotification()`; VAPID из конфига.
  - Ответ «подписка мертва» (HTTP 404/410) → деактивировать/удалить этот Channel (через репозиторий).
  - `sendVerificationCode` — no-op (push не верифицируется OTP; при желании — тест-пуш).
- Вписать в `NotifierFactory::create()`: `WEB_PUSH => $webPushNotifier` (match остаётся исчерпывающим).

## Application / HTTP (подписка)
- Команда `SubscribeToWebPush` (+handler): текущий юзер (`AuthUserFetcher`) + пришедшая подписка →
  создать/сохранить WEB_PUSH `Channel` (дедуп по `endpoint`). Опц. `UnsubscribeWebPush` (по endpoint).
- Контроллер `POST /cabinet/push/subscribe` (авторизованный, тонкий) → команда. Опц. отписка.
- Отдать VAPID public-ключ клиенту.

## Service worker (`public/sw.js` — сейчас только offline-кэш)
- `push` → `self.registration.showNotification(payload.title, {body, icon, data:{url}})`.
- `notificationclick` → сфокусировать/открыть `data.url`.
- `pushsubscriptionchange` → переподписаться и переслать на `/cabinet/push/subscribe`.
- Поднять `CACHE`-версию (иначе новый SW не активируется).

## Фронт (Stimulus)
- `push_subscribe_controller.js`: кнопка «Включить уведомления» (в кабинете — «Ещё»/настройки) →
  `Notification.requestPermission()` → `registration.pushManager.subscribe({ userVisibleOnly:true,
  applicationServerKey: <VAPID public → Uint8Array> })` → POST подписки. Состояния:
  default/granted/denied/уже подписан; кнопка «выключить».
- Разрешение — только по клику (не на загрузке).

## Тесты
- Юнит `WebPushNotifierTest`: `isSupportedChannel`; `notify` собирает Subscription/payload (клиент
  web-push застабить); `sendVerificationCode` — no-op.
- Юнит/интеграция фабрики: `WEB_PUSH → WebPushNotifier`.
- Функц. `SubscribeAction`: авторизованный POST создаёт WEB_PUSH Channel; аноним → 401.
- SW/JS — вручную (push требует https или localhost + реального устройства/браузера).

## Развилки к подтверждению
- VAPID: secrets (приватный) — согласны?
- `Channel.value` под JSON — проверить длину колонки (миграция при нужде).
- WEB_PUSH-канал `verified` сразу (в обход OTP) — не ломает ли инварианты верификации канала.
- Где кнопка «включить уведомления» (кабинет: «Ещё»/настройки).

## Предпосылки / ограничения
- HTTPS на проде (localhost для теста ок). iOS — только у PWA, установленной на домашний экран.

## Верификация
`./run check` + ручной прогон в браузере (подписка → тест-пуш → приём → клик открывает url).

## Статус
План на согласование. Реализацию не начинаем без подтверждения.
