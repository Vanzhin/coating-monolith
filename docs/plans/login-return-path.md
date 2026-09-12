# Возврат на исходную страницу после входа (magic-link)

Отдельный деплой (контекст Users), не часть арки «Инструменты». Триггер: со страницы
инструмента жмёшь «Войти» → после входа кидает в кабинет, а не назад на инструмент — ломается
ростовой хук раздела.

## Root cause (найдено)

- Вход — **magic-link**. `LoginLinkProcessAction::__invoke` после `security->login(...)` делает
  **жёстко** `redirectToRoute('app_cabinet')` — origin игнорируется (`.../Security/LoginLinkProcessAction.php:51`).
- Штатный `TargetPathTrait` в `LoginFormAuthenticator` есть, но target path сохраняет только
  `start()` — когда аноним УПЁРСЯ в защищённую страницу. Инструменты публичны, «Войти» жмут
  добровольно → `start()` не срабатывает → target path пуст → дефолт (cabinet).
- `SecurityController::login` (`/login`) referrer не запоминает.

## Symfony-механизм

`TargetPathTrait` (`saveTargetPath`/`getTargetPath`) + конвенция `_target_path`. Но magic-link —
круговой рейс через письмо (ссылку могут открыть в другом браузере), поэтому origin надо везти
**вместе с хэшем** ссылки (в Redis, где уже лежит `userUlid`), а не в сессии.

## Фикс

1. Ссылка «Войти» несёт текущий URL: `path('app_login', {_target_path: app.request.requestUri})`.
   Добавить в приманку `tools/mix.html.twig` и (опц.) в лендинг.
2. `/login` и форма запроса ссылки (`login_link`) протаскивают `_target_path` (hidden-поле /
   query), не теряя при POST.
3. При создании ссылки (`LoginLinkCreatedEvent`/`LoginLinkCreatedEventHandler` → Redis) класть
   рядом с `userUlid` валидированный `targetPath`.
4. `LoginLinkProcessAction` редиректит на сохранённый `targetPath` вместо хардкода `app_cabinet`;
   если пусто/невалидно — cabinet.
5. **Open-redirect guard**: принимать только локальные пути (начинается с одного `/`, не `//`,
   без схемы/хоста). Хелпер валидации; отвергнутое → cabinet.

## Файлы (ориентировочно)
- `Users/Infrastructure/Controller/Security/LoginLinkAction.php`, `LoginLinkProcessAction.php`.
- `Users/Domain/Event/LoginLinkCreatedEvent.php` + `EventHandler/LoginLinkCreatedEventHandler.php`
  (протащить targetPath в Redis).
- `Shared/Infrastructure/Controller/Security/SecurityController.php` (или где `/login`),
  шаблоны `security/login_link.html.twig` / `_login_form.html.twig` (hidden `_target_path`).
- Вызовы: `tools/mix.html.twig` (приманка), лендинг.

## Тесты
- Функц.: запрос ссылки с `_target_path=/tools/mix` → после обработки ссылки редирект на
  `/tools/mix`; `_target_path` с внешним хостом/`//evil` → редирект на cabinet (guard).
- Юнит: хелпер валидации локального пути.

## Верификация
`./run check` + ручной прогон флоу в браузере.
