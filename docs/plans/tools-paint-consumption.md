# Калькулятор расхода краски

Инструмент раздела `/tools`, по принципу калькулятора толщины плёнки: публичная страница,
расчёт на клиенте (офлайн), SEO-рендер, ростовой хук (VS из покрытия для залогиненных),
оговорка о погрешности. Оживляет «скоро»-карточку «Расход материала» (`bi-bucket`).

## Физика (согласовано)
- Теоретический расход неразбавленной краски: **л/м² = DFT(мкм) / (VS(%) × 10)** (= объём мокрой
  плёнки на м², WFT×10⁻³). Разбавление на расход НЕ влияет.
- Практический расход = теоретический / **(1 − потери/100)** = ×коэффициент (30% потерь → легло
  70% → ×1.43). Потери % ⇄ коэффициент связаны в обе стороны.
- Площадь ⇄ всего краски: всего = расход × площадь; площадь = объём / расход.
- Фасовка: всего л → **число вёдер** = ceil(всего / фасовка), с коротким пояснением. Фасовка (`pack`)
  из покрытия.
- Масса: расход кг/м² и всего кг через `massDensity` покрытия.
- Единицы: объём (л/м², всего л) + масса (кг/м², всего кг).

## Domain-сервис (источник истины) — СДЕЛАН
`Coatings/Domain/Service/PaintConsumptionCalculator` — **переиспользует** `FilmThicknessCalculator`
(WFT×10⁻³ = л/м²), не дублируя физику. Все числовые параметры — VO (Percent/PositiveNumber):
- `litersPerSquareMeter(Percent $vs, PositiveNumber $dry, Percent $loss): float`
- `lossCoefficient(Percent $loss): float` — 1/(1−loss/100), отсечка 100%
- `totalLiters(PositiveNumber $rate, PositiveNumber $area): float`
- `coverageSquareMeters(PositiveNumber $rate, PositiveNumber $liters): float`
VS>0 — через FilmThicknessCalculator. Юнит-тест `PaintConsumptionCalculatorTest`. HTTP-эндпоинта нет.
Вёдра (ceil) и масса (×density) — добавлю при вёрстке страницы (простая арифметика поверх этих).

## UX (как плёнка, вариант A)
- Параметры (ввод): Сухая плёнка DFT (мкм), Сухой остаток VS (%), Потери (%).
- Расход **л/м²** — read-only плитка (как «фактический сухой остаток» в плёнке).
- Пара конвертации (обе стороны): **Площадь (м²) ⇄ Всего краски (л)** — опорная та, куда ввели.
- Единицы подписаны; ввод фильтруется (запятая→точка); ростовой хук: VS из покрытия (suggest уже
  несёт `volumeSolid`), лок VS, тег/замок; аноним → CTA «Войти» с `_target_path`.
- Оговорка `.tools-note`: расчёт по номиналу, факт зависит от погрешности/профиля/потерь.

## Файлы
- `Shared/Infrastructure/Controller/Tools/PaintConsumptionAction.php` (`/tools/consumption`,
  `app_tools_consumption`).
- `Templates/tools/consumption.html.twig` (структура как film).
- `assets/controllers/consumption_calculator_controller.js` (Stimulus; обе стороны; sanitize;
  VS из покрытия; расход л/м² live).
- `tools.css`: переиспуём `.film-*`/`.tools-note` (при нужде добить).
- `tools/index.html.twig`: оживить карточку `bi-bucket`.
- `public/sw.js`: precache `/tools/consumption` (bump версии).
- Suggest `volumeSolid` — уже есть (из калькулятора плёнки), бэкенд не трогаем.

## Тесты
- Юнит: `PaintConsumptionCalculatorTest` (сделан).
- Функц.: `GET /tools/consumption` → 200 публично (в ToolsPagesTest).
- JS — без PHP-тестов (клиентская арифметика).

## Верификация
`./run check` + `yarn dev` + браузер (обе стороны, потери, подстановка VS из покрытия).

## Статус
- Домен `PaintConsumptionCalculator` (+ packsNeeded, massKilograms, lossCoefficient) + тест — СДЕЛАНО.
- Страница `/tools/consumption` (экшен + шаблон + `consumption_calculator` Stimulus): параметры
  DFT/VS/потери⇄коэффициент/плотность/фасовка → расход л/м² → площадь⇄всего; тумблер **Объём/Масса**
  (масса = ×плотность), вёдра (ceil) при заданной фасовке. suggest несёт `pack`/`massDensity`/
  `volumeSolid`, карточка хаба `bi-bucket` оживлена, SW precache `/tools/consumption` (app-v5),
  SEO+оговорка. Тесты: ToolsPagesTest (+consumption), SuggestActionTest (+pack/massDensity).
- **Вариант B** (уточнено заказчиком): всё вводится вручную и работает без входа (тумблер, масса,
  вёдра доступны анониму); покрытие лишь автозаполняет VS/плотность/фасовку (и запирает их до
  «убрать покрытие»).
