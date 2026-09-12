# Sitemap + robots для публичных страниц (отдельная SEO-задача)

Самостоятельная задача на весь сайт, не часть арки «Инструменты». Цель — чтобы поисковики
находили и обходили публичные страницы (лендинг, раздел «Инструменты» и его калькуляторы).

## Контекст

- Сейчас в проекте НЕТ ни `sitemap.xml`, ни `robots.txt` (проверено).
- Публичные страницы = всё, что НЕ под `^/api`, `^/cabinet`, `^/user` (см. `security.yaml`).
  Каталог покрытий/систем/документов сидит под `/cabinet` (только авторизованным) → в sitemap
  НЕ идёт. Индексируемое сейчас: `/` (лендинг), `/tools`, `/tools/mix`; далее — новые
  инструменты раздела.

## Подход

- **Динамический** `GET /sitemap.xml` — тонкий публичный экшен в
  `Shared/Infrastructure/Controller/` (как `HomePageAction`), отдаёт XML (Twig-шаблон
  `sitemap.xml.twig` или прямой `Response` с `Content-Type: application/xml`). Динамический,
  а не статический файл — чтобы список рос вместе с разделом.
- Список публичных URL — явный, поддерживаемый в одном месте (MVP): `app_homepage`,
  `app_tools_index`, `app_tools_mix`. Пополняется при добавлении инструментов. (Альтернатива на
  вырост — помечать публичные роуты атрибутом/тегом и собирать автоматически; для MVP избыточно.)
- Абсолютные URL — генерировать роутером с абсолютным контекстом (`UrlGeneratorInterface::ABSOLUTE_URL`);
  host/scheme берётся из запроса. Опц. `lastmod`/`changefreq`/`priority`.
- `public/robots.txt` (статический): `User-agent: *`, `Allow: /`, `Disallow: /api /cabinet /user`,
  строка `Sitemap: https://<host>/sitemap.xml`. Host в robots.txt статичен — взять из
  канонического домена проекта (уточнить прод-домен).

## Файлы

- Новый: `Controller/SitemapAction.php` (`#[Route('/sitemap.xml', name: 'app_sitemap')]`),
  при необходимости `Templates/sitemap.xml.twig`.
- Новый: `public/robots.txt`.
- Тест: `tests/Functional/.../SitemapTest.php`.

## Тесты / верификация

- Функц.: `GET /sitemap.xml` → 200, `Content-Type` xml, содержит `<loc>…/tools</loc>` и
  `…/tools/mix`; анониму доступен. `GET /robots.txt` → 200.
- `./run check` + `curl -s /sitemap.xml`.

## Развилки к уточнению

- Канонический прод-домен для абсолютных URL и строки `Sitemap:` в robots.
- Нужны ли в sitemap страницы входа/регистрации (обычно `noindex` — исключить).
