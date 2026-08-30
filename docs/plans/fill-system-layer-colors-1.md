# Деплой 1: бэкфил цветов слоёв систем покрытий (консольная команда)

Связанный план: `fill-system-layer-colors-2.md` (после успешного бэкфила — сделать цвет слоя обязательным).

## Задача

Разово проставить цвет во всех слоях систем покрытий, где он не задан (`color_id IS NULL`, легаси-слои). Правило выбора цвета:

- Слой уже с цветом — не трогаем.
- Слой без цвета: берём **первый доступный цвет покрытия** (`Coating::getPossibleColors()->first()`).
- Если у покрытия доступных цветов нет (это возможно только у `isTintable = true` — «мультиколор»; у не-колеруемого инвариант гарантирует ≥1 цвет) → ставим дефолтный **серый**.

## Что уже выяснено (факты кода/БД)

- Слой `CoatingSystemLayer` держит ровно один `?Color $color` (nullable ради легаси). `assertColorAllowed` пропускает `null` и любой цвет для `isTintable`, иначе проверяет членство в `possibleColors`.
- `Coating`: `isTintable` (bool) + `possibleColors: Collection<Color>`. Инвариант `applyColorScheme`: у не-колеруемого покрытия минимум один цвет.
- Дефолтный серый в БД — единственный `Color` с именем **«Серый»**, `hex = #888888`, без RAL (`id 01a017fd-47d1-7e6a-a953-45c56dfb11ce`). Остальные серые оттеночные (Голубовато-/Зеленовато-/Светло-серый) — не берём.
- Резолв серого: `ColorRepositoryInterface::findOneByNameAndHex('Серый', '#888888')` (существующий метод, новый не нужен).
- Пересбор слоёв: `CoatingSystem::replaceLayers(array $items)`, item — `['coating' => Coating, 'dft' => int, 'color' => ?Color]`. Чистит и пересоздаёт слои с новыми UUID (orphan-removal). Порядок массива = позиции 1..N. Инварианты — в `postMutate()`.
- Репозиторий систем: `CoatingSystemRepositoryInterface::findAll()` (джойнит слои/покрытия/цвета) и `save()`.
- Образец батч-команды: `RebuildCoatingSystemSearchCacheCommand` (`app/src/Coatings/Infrastructure/Console/`).
- dry-run как в `ImportCoatingSystemsCommand`.

## Развилки (согласовано с пользователем)

- Серый = «Серый / #888888». Ок.
- Механизм записи цвета — через `replaceLayers` (без нового доменного мутатора `updateLayerColor`).
- `--dry-run` — нужен.

## Реализация

Домен не трогаем (меньше кода). Выбор цвета — прямо в команде: `getPossibleColors()->first() ?: $grey` (`Collection::first()` на пустой коллекции отдаёт `false`, `?:` подставляет серый).

### Команда

Файл: `app/src/Coatings/Infrastructure/Console/FillSystemLayerColorsCommand.php`.

- `#[AsCommand(name: 'app:coating-system:fill-layer-colors', description: 'Бэкфил: проставить цвет во всех слоях систем без цвета (первый доступный цвет покрытия, для мультиколора — серый).')]`.
- Конструктор (autowire): `CoatingSystemRepositoryInterface $systemRepo`, `ColorRepositoryInterface $colorRepo`.
- `configure()`: `addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, сколько слоёв заполнится, без записи')`.
- `execute()`:
  1. `$grey = $colorRepo->findOneByNameAndHex('Серый', '#888888')` — при `null` вывести ошибку («Дефолтный серый (Серый/#888888) не найден в БД») и вернуть `Command::FAILURE`, ничего не меняя.
  2. Счётчики: systems scanned, systems changed, layers filled (в т.ч. отдельно filled-with-grey).
  3. `foreach ($systemRepo->findAll() as $system)`:
     - Пройти `getLayers()`. Если ни у одного слоя нет `null`-цвета — систему пропустить.
     - Иначе собрать `$items` из существующих слоёв: `coating` и `dft` как есть; `color` = существующий, если есть, иначе `$layer->getCoating()->getPossibleColors()->first() ?: $grey`.
     - Если не dry-run: `$system->replaceLayers($items); $systemRepo->save($system);`.
     - Инкремент счётчиков.
  4. `SymfonyStyle`: итог (`success`/`note` для dry-run) с числами.

### Тесты

Команду не покрываем функционалкой отдельно (в проекте у аналогичных backfill-команд `RebuildCoatingSystemSearchCacheCommand` теста нет). Верификация — `--dry-run` + прогон на локальной БД.

## Файлы

- `app/src/Coatings/Infrastructure/Console/FillSystemLayerColorsCommand.php` — новая команда (единственный файл).

## Проверка

```bash
./run console app:coating-system:fill-layer-colors --dry-run   # сколько заполнится
./run console app:coating-system:fill-layer-colors             # накатить
cd app && vendor/bin/phpunit tests/Unit/Coatings/Domain/Aggregate/Coating
```

## Замечание про replaceLayers

`replaceLayers` пересоздаёт слои с новыми UUID (orphan-removal). Для бэкфила приемлемо (пользователь выбрал этот путь). Ничего внешнего на id слоёв не завязано.
