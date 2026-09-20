# Глобальный перехват 500 + единая обработка ошибок мутаций

> **Для исполнителя:** subagent-driven-development, по задачам, с ревью между ними.
> Отдельная ветка от main. Юнит — на хосте, функциональные — в контейнере
> (`manager_php-fpm`). Гейты `./run check` (style/phpstan/unit/functional) в каждую задачу.
> Кода в рабочее дерево ещё НЕ писали — это спека.

**Задача:** #17. Необработанная 500 больше не роняет приложение «страницей ошибки» на
экшенах — пользователь остаётся на своей странице, видит понятный тост, реальная ошибка
уходит в бэк-лог с кодом. Известные кейсы (напр. удаление покрытия, используемого в
системах) дают точное доменное сообщение, а не generic. Обработка ошибок мутаций
унифицируется.

**Триггер:** удаление покрытия, которое используется в системе → `flush()` бросает
`ForeignKeyConstraintViolationException`, её никто не ловит → 500.

## Решения (согласовано с владельцем)
- **D1 = b, реализация b1** (листенер-driven): глобальный листенер сам превращает ошибку
  мутации в flash+redirect; контроллеры тоньше, без копипаст-try/catch. Формы create/update
  опт-аутятся тем, что ловят `AppException` В КОНТРОЛЛЕРЕ (для inline-ошибки в форме).
- **D2:** `error500.html.twig` (полная страница) остаётся крайним фолбэком — для GET и всего,
  что листенер не перехватил.
- **D3:** ref-код — короткий id (напр. 8 hex), пишется в лог с реальным исключением и
  показывается пользователю в сообщении (связка «тост ↔ запись лога» без утечки внутренностей).
- **D4:** чиним хрупкий exact-Accept в `ExceptionListener` (`===` → нормальная проверка
  «ждёт JSON»).
- **D5:** флеши унифицируем на тост (`base.html.twig`), собственный alert-блок из
  `cabinet/index.html.twig` убираем (cabinet наследует base → тост уже доступен).

## Текущее состояние (выверено разведкой)
- Листенеры `kernel.exception` (атрибутные): `ForbiddenExceptionListener` (prio 200,
  stopPropagation, 403), `AppExceptionHtmlListener` (195, HTML→`error422`, статус хардкод 422),
  `ExceptionListener` (190, только `Accept === 'application/json'`, иначе НЕ реагирует).
  → для необработанных исключений на HTML — спец-листенера НЕТ, падают в дефолтный
  `ErrorController` → `error500.html.twig` (prod) / debug-страница (dev).
- Тост уже есть: `base.html.twig:121` `<div class="flash-messages" data-controller="flash">`,
  `flash_controller.js`, `assets/flash_toast.js` `showToast(message,type)`. Типы флеша:
  `success/danger/error/warning/info` → `ok/err/warn/info`, прочее → `info`.
  `cabinet/index.html.twig:1` `extends base`, но ЕЩЁ и сам рендерит флеши (21-30) — двойной путь.
- `AppException` (`src/Shared/Infrastructure/Exception/AppException.php`): `extends \Exception`,
  дефолт-код 422, 4-й арг `array $log`. `ForbiddenException extends AppException` (403) —
  единственный подкласс.
- `CommandBus::execute` разворачивает `HandlerFailedException` → исходное исключение долетает
  до контроллера как есть.
- Эталон доменного FK-месседжа УЖЕ есть:
  `ChemicalResistance/.../DeleteSubstanceCommandHandler` ловит
  `ForeignKeyConstraintViolationException` → `throw new AppException('… используется …')`.
  У `RemoveCoatingCommandHandler` этого НЕТ.
- Мутации-контроллеры (~40). Опт-аут (рендерят форму с inline `error`, ловят AppException сами):
  `Certificates/Document/{Add,Update}`, `Certificates/Issuer/{Add,Update}`,
  `ChemicalResistance/Assessment/Update`, `ChemicalResistance/Substance/{Add,Update}`,
  `Coatings/Coating/Update`, `Coatings/CoatingSystem/{Add,Update}`,
  `Coatings/Manufacturer/ManufacturerController`, `Coatings/SurfaceTreatment/{Add,Update}`.
  Остальные (delete/clone/remove/simple) — под листенер.

## Архитектура

### Квадранты (что где обрабатывается)
| Запрос | AppException (в т.ч. Forbidden) | Неожиданное (любой Throwable) |
|---|---|---|
| HTML, небезопасный метод (POST/PUT/DELETE) | **redirect-back + flash(message)** | **лог+ref, redirect-back + flash(generic+ref)** |
| HTML, GET | `error422`/`error403` страница (как сейчас) | `error500` страница (как сейчас, D2) |
| Ждёт JSON (AJAX, любой метод) | `{message}` (+403 статус для Forbidden) | `{message: generic, ref}` 500 |

Левая-верхняя и правая-верхняя ячейки — НОВОЕ (Часть 1). Опт-аут форм: контроллер сам ловит
`AppException` до листенера → inline-ошибка, эта ячейка для него не наступает.

### Часть 1 — глобальный листенер мутаций
Новый `App\Shared\Infrastructure\EventListener\Exception\MutationErrorListener`
(`#[AsEventListener]`, приоритет ВЫШЕ 200, напр. 210 — перехватить unsafe-HTML раньше
Forbidden/AppExceptionHtml). Логика:
```
onKernelException(ExceptionEvent $event):
  if kernel.debug: return                      # dev — стектрейс Symfony не трогаем
  $req = event.request
  if requestExpectsJson($req): return          # JSON отдаёт ExceptionListener (Часть 4)
  if not requestIsHtml($req): return           # не HTML (напр. text/html-partial GET — не мутация)
  if req.method безопасный (GET/HEAD): return   # GET → дефолтные error-страницы (D2)

  $e = event.throwable
  if $e instanceof AppException:               # incl. ForbiddenException
      $message = $e.getMessage()               # читаемое доменное сообщение
      $flashType = 'danger'
  else:
      $ref = bin2hex(random_bytes(4))          # 8 hex
      logger.error($e.getMessage(), ['ref'=>$ref, 'exception'=>$e, 'route'=>..., 'method'=>...])
      $message = "Что-то пошло не так. Мы уже разбираемся — попробуйте ещё раз. Код: $ref"
      $flashType = 'danger'

  addFlash($flashType, $message)               # через RequestStack->getSession()->getFlashBag()
  $target = safeReferer($req) ?? route('app_home' | 'app_cabinet_dashboard')
  event.setResponse(new RedirectResponse($target))
  event.stopPropagation()
```
- **safeReferer:** брать `Referer`, пускать ТОЛЬКО same-origin (защита от open-redirect); иначе
  fallback на безопасный маршрут. (Проверить хост через `$req->getSchemeAndHttpHost()`.)
- **ref только для неожиданных.** Для `AppException` кода нет — это ожидаемое доменное сообщение.
- **Логирование:** только неожиданные (AppException — не ошибка сервера, не спамим error-лог;
  можно debug/info при желании, но по умолчанию не логируем).
- **Приоритет/stopPropagation:** 210 + stop — чтобы Forbidden(200)/AppExceptionHtml(195) не
  перерисовали ответ для unsafe-HTML. Их поведение для GET/JSON остаётся.
- **flashBag:** сессия должна существовать (в cabinet есть). Если сессии нет — деградируем на
  `error500`/`error422` страницу (не падаем).

### Часть 2 — доменное сообщение удаления покрытия
`RemoveCoatingCommandHandler::__invoke` — обернуть `remove()` (зеркало DeleteSubstance):
```php
try {
    $this->coatingRepository->remove($coating);
} catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
    throw new AppException('Покрытие используется в системах покрытий, удаление невозможно.');
}
```
Тогда FK → `AppException` → листенер Части 1 покажет этот текст тостом на той же странице.
(Опционально/лучше по DDD: доменная проверка «используется в системах» до remove — но нужен
запрос использования; для v1 достаточно перехвата FK, как у substance.)

### Часть 3 — унификация флешей (D5)
- Убрать флеш-рендер из `cabinet/index.html.twig` (строки ~21-30). Cabinet наследует base →
  тост из `base.html.twig` покажет всё. Проверить, что не остаётся двойного рендера/консюма.
- Убедиться, что тип флеша `danger` маппится в `err` (да, по `flashKinds`).
- Легаси-ключи флешей (`coating_system_error`, `manufacturer_update_error`, `..._success`) —
  привести к типам из маппинга (`success`/`danger`), иначе падают в `info`. В рамках b1 многие
  из этих `addFlash` уйдут (листенер возьмёт ошибки), но success-флеши остаются — их ключи
  причесать к `success`.

### Часть 4 — надёжная проверка «ждёт JSON» (D4)
`ExceptionListener.php:29`: заменить `self::MIME_JSON === $acceptHeader` на общий хелпер
`requestExpectsJson($request)`: `$req->getPreferredFormat() === 'json'` ИЛИ
`str_contains((string)$req->headers->get('Accept'), 'application/json')` ИЛИ
`$req->isXmlHttpRequest()`. Вынести хелпер в одно место (напр. трейт/статик-метод), чтобы и
`MutationErrorListener` (Часть 1), и `ExceptionListener` использовали ОДНУ логику определения
формата — иначе снова разъедутся.

### Часть 5 — причёсывание контроллеров (b1)
- **Delete/простые мутации** (не из опт-аут списка): убрать ручной `try/catch` где он есть
  (напр. `CoatingSystem/RemoveAction`, `SurfaceTreatment/RemoveAction`) — листенер теперь
  сам ловит. Контроллер: csrf → dispatch → addFlash(success) → redirect. `Coating/DeleteAction`
  — оставить как есть (уже без catch), листенер подхватит FK-AppException.
- **Формы (опт-аут список)** — НЕ трогать логику inline-ошибки: они ловят `AppException` сами
  и рендерят форму с `error`. Проверить только, что они НЕ перехватывают лишнего (напр.
  `catch (\Exception)` вместо `catch (AppException)` — тогда неожиданная 500 замаскируется под
  inline-ошибку формы; желательно сузить до `catch (AppException)` + пусть остальное летит в
  листенер). Это отдельная под-проверка на каждый файл.
- Success-редиректы и флеши-успеха оставить; причесать только тип флеша к `success`.

## Задачи (bite-sized, TDD)
- **T1. Хелпер формата запроса** (`requestExpectsJson`/`requestIsHtml`) + юнит-тест
  (json/ajax/html/partial). Одна точка правды.
- **T2. Фикс exact-Accept в ExceptionListener** на хелпер T1 + тест (AJAX с расширенным Accept
  теперь получает JSON, не HTML).
- **T3. MutationErrorListener** (Часть 1): dev-bypass, квадранты, ref+лог, safeReferer,
  stopPropagation. Функциональные тесты: (a) unsafe-HTML + AppException → 302 на referer + флеш
  с текстом; (b) unsafe-HTML + RuntimeException → 302 + флеш с «Код: …» + запись в лог с ref
  (проверить через тест-логгер); (c) GET + RuntimeException → `error500` страница (не редирект);
  (d) JSON + RuntimeException → JSON `{message,ref}`; (e) dev — не вмешивается.
- **T4. RemoveCoatingCommandHandler FK → AppException** (Часть 2) + функц.тест: удаление
  покрытия, используемого в системе, → редирект + флеш «используется в системах», покрытие
  на месте. (Зеркалить DeleteSubstance-тест.)
- **T5. Унификация флешей** (Часть 3): убрать alert-блок из `cabinet/index.html.twig`,
  причесать success-ключи; `yarn dev`; `lint:twig`; ручной/функц. смоук, что тост виден.
- **T6. Причёсывание контроллеров** (Часть 5): убрать лишние try/catch у delete/простых;
  сузить `catch (\Exception)`→`catch (AppException)` у форм, где нужно. По батчам, диффом.
  Функц.тесты затронутых экшенов зелёные.
- **T7. Гейты + финальный ревью:** `./run check`, широкий ревью ветки.

## Тестирование
- Unit: хелпер формата, генерация ref, safeReferer (same-origin/чужой→fallback).
- Functional (реальная БД): квадранты листенера; FK удаления покрытия; опт-аут форм (inline
  ошибка НЕ стала редиректом); success-флеш виден как тост.
- Тест-логгер: убедиться, что неожиданная ошибка попала в лог с тем же ref, что показан юзеру.

## Развилки/риски (решить при реализации)
- **Forbidden на unsafe-HTML:** сейчас план заворачивает и его в flash+redirect (ForbiddenException
  — подкласс AppException). Если для CSRF/authz-провала хотим оставить жёсткую 403-страницу —
  исключить `ForbiddenException` из редиректной ветки листенера (тогда Forbidden(200) отработает
  как сейчас). РЕКОМЕНДАЦИЯ: на unsafe-HTML показывать флеш «Недостаточно прав/недействительный
  токен» + redirect (UX ровнее), но это на подтверждение.
- **Партиалы (GET partial=1, Accept text/html):** это GET → листенер их не трогает, JS сам
  показывает «Ошибка». Ок.
- **Сессия/флеш вне cabinet:** публичные страницы без сессии — деградировать на error-страницу.
- **Двойной flush/консюм флеша** при уборке cabinet-alert — проверить визуально.
- **Open-redirect** через Referer — строгая same-origin проверка обязательна.

## Отдельно (НЕ эта задача)
- Minor'ы аудита (пропорция ±, старые записи JSON, enum-подписи) — свои задачи.
