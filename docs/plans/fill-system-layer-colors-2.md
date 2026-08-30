# Деплой 2: цвет слоя системы — обязательный (убрать nullable-легаси)

Связанный план: `fill-system-layer-colors-1.md` (бэкфил цветов). **Этот деплой делать только ПОСЛЕ успешного бэкфила** — иначе существующие легаси-слои без цвета не пройдут инвариант/NOT NULL.

## Задача

Сделать так, что слой системы (`CoatingSystemLayer`) **не соберётся без цвета**. Сейчас `?Color $color` nullable «ради легаси», `assertColorAllowed` пропускает `null`. После бэкфила легаси-слоёв без цвета не остаётся — nullable больше не нужен, убираем как источник багов.

## Что меняем (набросок, детализировать в отдельной сессии)

- `CoatingSystemLayer.__construct`: `Color $color` вместо `?Color $color = null`. `getColor(): Color`. `assertColorAllowed` — `null` больше не валиден (метод либо перестаёт принимать null, либо падает на нём).
- `CoatingSystem`: `appendLayer(Coating, int, Color)`, `insertLayerAt(int, Coating, int, Color)`, `replaceLayers` item — `color` обязателен (`array{coating, dft, color: Color}`).
- ORM `CoatingSystem.CoatingSystemLayer.orm.xml`: `color_id` → `nullable="false"`.
- Миграция `Version*`: `ALTER TABLE ... ALTER COLUMN color_id SET NOT NULL` — идемпотентная, с проверкой, что незаполненных нет (иначе миграция должна упасть с понятным сообщением).
- Форма уже требует `colorId` (`CoatingSystemMapper`, `Assert\NotBlank`) — согласуется, доп. правки не нужны, но перепроверить.
- Прибрать весь код-ветвления «если цвет null» (легаси-обработку) по контексту Coatings — грепнуть `getColor()` / `?Color`.

## Тесты

- `CoatingSystemLayer` конструктор без цвета → раньше проходил, теперь ошибка типа/AppException.
- Существующие тесты систем, где слои строились без цвета, — обновить (добавить цвет).

## Предусловие деплоя

Перед раскаткой миграции убедиться, что `SELECT count(*) FROM coating_system_layer WHERE color_id IS NULL = 0`. (Уточнить фактическое имя таблицы слоёв при реализации.)
