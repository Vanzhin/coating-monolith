# Апгрейд Фаза 3b — Symfony 8.0 ядро (на PHP 8.5, поверх 3a)

Ступень после 3a (Doctrine/DBAL4). Ветка `upgrade-3b-sy8core` (от upgrade-3a-doctrine). Родитель-план:
`symfony8-php85-upgrade.md`. Соседи: 3a (done), 3c (dissolved — lexik/monolog вынужденно вошли сюда, gesdinet в 3a).

## Бампы (composer.json)
- `extra.symfony.require: 7.4.* → 8.0.*` + все явные `symfony/*: 8.0.*`; `symfony/phpunit-bridge: ^8`.
- `lexik/jwt-authentication-bundle: ^2.21 → ^3.2` (2.x капит symfony ^7 → форсится ядром).
- `symfony/monolog-bundle: ^3.10 → ^4.0` (то же).
- **twig/twig: обратно `>=3.21 <3.29`** — «снять пин» оказалось НЕЛЬЗЯ: twig 3.29 сменил `TemplateWrapper::unwrap()`
  на 1-арг (`unwrap(Environment $env)`), а twig-bridge 8.0.15 (`TwigRendererEngine:142`) всё ещё зовёт 0-арг →
  форма-рендер падает "Too few arguments to unwrap()". Констрейнт bridge `^3.21|^4.0` его пускает, но код не готов.
  Держим 3.28 до фикса в будущем twig-bridge. Резолв: symfony 8.0.15, lexik 3.2, monolog-bundle 4.1, twig 3.28.

## BC-правки кода (результат — `./run check` ЗЕЛЁНЫЙ под PHP 8.5)
- **`Routing\Annotation\Route` → `Routing\Attribute\Route`** во ВСЕХ контроллерах (118 файлов). Sy8 удалил
  namespace `Routing\Annotation\` (есть только `Attribute\`). Без этого не грузился НИ ОДИН роут ("No route found").
- **`Request::get()` удалён в Sy8**: `pager.html.twig` (×3) `app.request.get('_route')`→`app.request.attributes.get('_route')`;
  `LoginLinkProcessAction` `$request->get('hash')`→`$request->query->get('hash')`.
- **`RateLimiterFactory` → `RateLimiterFactoryInterface`** (3 контроллера: Registration/LoginLink/ChannelVerification):
  Sy8 типизирует `limiter.*` сервисы интерфейсом; инъекция конкретного класса ломала конструктор → 500 на странице.
- **Валидатор-констрейнты форм на named args** (3 Users-формы: ChannelVerification/CreateChannel/Registration):
  `new Length([...])`/`new Regex([...])`/`new NotBlank([...])` → `(min:.., message:..)`. Sy8 убрал array-конструктор
  (в Фазе 1 сделал для 5 мапперов, эти формы пропустил — их констрейнты не инстанцировались на 7.4-тестах).
- Проверено чисто: `Security\Core\Security`, `getUsername()`, `getDoctrine()`, ContainerAware, `@Route/@ORM`-аннотации — не используются.

## Верификация
`./run check` под PHP 8.5.10: style / phpstan(baseline) / unit(1009) / functional(480) ЗЕЛЁНЫЕ. Гейт
`SYMFONY_DEPRECATIONS_HELPER=max[self]=0` держит. 57 вендорных deprecations (не наш код). Flex при апдейте лез с
stateless-CSRF рецептом — откачен (отдельное решение). JWT-тесты проходят → lexik v3 config совместим без правок.
ОСТАЛОСЬ: ручной фронт-смоук (twig-рендер форм, stimulus) + прод-смоук после деплоя (прогнать миграцию 3a).

## Дальше
3c как отдельной ступени НЕ будет (lexik v3/monolog 4 вошли сюда, gesdinet v2 в 3a). После деплоя 3a+3b апгрейд
PHP 8.5 + Symfony 8 завершён. Отложенные отдельные миграции (не блокеры): phpspreadsheet 3→5, web-push 10→11,
webmozart/assert 1→2; чистка phpstan-baseline Rector'ом; вычистить мёртвые getName()/requiresSQLCommentHint() в DBAL-типах.