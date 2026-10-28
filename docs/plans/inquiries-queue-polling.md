# Поллинг карточки запроса (Inquiries 4b follow-up)

Авто-обновление карточки show, пока запрос обрабатывается, чтобы не жать «Обновить» руками.

## Поведение
- Пока статус в обработке (`received`/`recognized`) — фронт опрашивает лёгкий статус-эндпоинт каждые **15 с**.
- Как только статус вышел из обработки (`matched`/`failed`/`approved`) — один `location.reload()`: сервер перерисует карточку целиком (системы/параметры/ошибка).
- Авто-стоп после **~3 мин** (воркер мог умереть): поллинг прекращается, на карточке уже есть ссылка «Обработка зависла? Запустить заново».

## Что делаем
1. **Домен**: `InquiryStatus::isProcessing(): bool` (`Received`/`Recognized` = true) — жизненный цикл сам знает, какие состояния «в работе».
2. **Query**: `GetInquiryStatusQuery(inquiryId)` + Handler → лёгкий `InquiryStatusView {status, processing}`, гейт `canManage`, null → 404. Не тянет параметры/вложения (в отличие от GetInquiry).
3. **Controller**: `InquiryStatusAction` `GET /cabinet/inquiry/{id}/status` → JSON `{status, processing}` (как by-ids/suggest — cabinet+JSON).
4. **Stimulus** `inquiry-status-poll`: values `endpoint`, `interval` (15000), `max-duration` (180000). `setInterval` → fetch; `!processing` → clearInterval + reload; по истечении max — clearInterval. `disconnect` чистит таймер.
5. **Twig**: контроллер вешаем на алерт «идёт подбор» в `show.html.twig` (блок `processing`).
6. **Тест**: функциональный — статус-эндпоинт даёт `processing:true` для свежего Received, 404 на отсутствующем, forbidden не-админу.
7. `yarn dev` (новый JS-контроллер).

## Развилки (решены)
- Reload целиком по готовности, не фрагмент-свап.
- Интервал 15 с, авто-стоп 3 мин.
- Эндпоинт под `/cabinet` (JSON).
