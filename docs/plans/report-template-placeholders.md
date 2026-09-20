# Контракт плейсхолдеров шаблонов отчёта

Список ключей, которые проектор (`ReportRenderDataProjector`) кладёт в `RenderData`. Автор `.docx`-шаблона
подставляет их как `{{ключ}}`. Это **контракт** между отчётом и шаблоном: имена должны совпадать буквально.

## Правила

- **Плейсхолдер** — `{{ключ}}`. Если поле может быть пустым (почти все, кроме обязательных) — ставь
  **опциональный** вариант `{{ключ?}}`: нет данных → плейсхолдер молча исчезает (presence-driven).
- **Скалярное поле блока** → ключ `{{blockKey}_{fieldKey}}` (напр. `{{surface_prep_rustGrade}}`).
  Имена полей — как в коде (у `surface_prep` они camelCase, в остальных snake_case). Копировать точно.
- **Слои** (`application`, `system`) раскладываются в **индексированные** ключи
  `{{blockKey}_layer{N}_{subKey}}` (N с 1), плюс счётчик `{{blockKey}_layer_count}}`. Движок без
  повтора → под каждое число слоёв (1–4) — свой файл-шаблон (или блок-регион на макс. число слоёв
  с опциональными ключами хвостовых слоёв).
- **Списки** (`instruments`, `process`, `recommendations`, `commission`) отдаются **одним** ключом-текстом:
  под-поля строки склеены через « — », строки — через перевод строки. Т.е. `{{commission_items?}}` —
  готовый многострочный блок, отдельных ключей по строкам нет.
- **Фото** (`photos`) пока **не проецируются** (отложено в use-case генерации; вставка картинок
  `ImageValue` + временные файлы). Будущие ключи: `{{photos_photo1?}}` … + подпись.
- Значения-ссылки (материал слоя `CoatingRef`) в документ идут **названием** покрытия (снимок).

## Шапка (оба типа акта)

| Ключ | Значение |
|------|----------|
| `{{act_number}}` | № акта |
| `{{report_date}}` | дата, формат `дд.мм.гггг` |
| `{{report_type}}` | название вида акта |
| `{{status}}` | статус (Создан / В работе / На проверке / Утверждён / Отклонён) |
| `{{address?}}` | адрес объекта (текст) |
| `{{work_period?}}` | период работ, «с дд.мм.гггг по дд.мм.гггг» (открытый — одна граница) |
| `{{project_title}}` | проект (снимок названия) |
| `{{customer_title}}` | заказчик |
| `{{contractor_title}}` | подрядчик |
| `{{system_title}}` | система покрытия |

## Блоки

Композиция по типам:
- **Акт выкрасов эталонного участка** (`reference_area`): control_area, surface_prep, system, application, notes, photos, commission.
- **Акт опытного нанесения** (`trial_application`): control_area, surface_prep, instruments, system, application, process, recommendations, conclusion, notes, photos, commission.

### Контролируемый участок (`control_area`)
- `{{control_area_description}}` — участок (обязателен на отправке)
- `{{control_area_area?}}` — площадь, м²

### Подготовка поверхности (`surface_prep`)
- `{{surface_prep_rustGrade}}` — степень ржавления
- `{{surface_prep_prepDegree}}` — степень подготовки
- `{{surface_prep_blasting_media?}}` — абразив (материал струйной очистки)
- `{{surface_prep_roughness?}}` — шероховатость
- `{{surface_prep_dedusting?}}` — обеспыливание

### Система покрытия — план (`system`), слои N = 1..`{{system_layer_count}}`
- `{{system_layer{N}_material}}` — материал (название)
- `{{system_layer{N}_dft_nominal?}}` — номинальная ТСП, мкм
- `{{system_layer{N}_color?}}` — цвет
- `{{system_layer{N}_order?}}` — № слоя
- `{{system_layer_count?}}` — число слоёв

### Приборы (`instruments`)
- `{{instruments_items?}}` — список (строки: название — серийный — датчик)

### Нанесение — факт (`application`), слои N = 1..`{{application_layer_count}}`
Общие поля блока (не по слою): `{{application_method?}}` — метод нанесения, `{{application_pump_system?}}` — аппарат/насосная система.
- Период нанесения (поле `applied`, дата+время с/по одним значением) → в акт разными частями:
  `{{application_layer{N}_date?}}` — дата (последняя, дд.мм.гггг), `{{application_layer{N}_time?}}` —
  интервал «ЧЧ:ММ–ЧЧ:ММ»; полные — `{{application_layer{N}_datetime_from?}}` / `{{..._datetime_to?}}`.
- `{{application_layer{N}_material}}` — материал (название)
- `{{application_layer{N}_color?}}` — цвет
- `{{application_layer{N}_batch_a?}}` — № партии, комп. А
- `{{application_layer{N}_batch_b?}}` — № партии, комп. Б
- `{{application_layer{N}_thinner?}}` — разбавитель, собранная строка «7% Название (№ партии XXX)»
- `{{application_layer{N}_thinner_name?}}` / `{{..._thinner_batch?}}` / `{{..._thinner_percent?}}` — части разбавителя
- `{{application_layer{N}_nozzle?}}` — сопло
- `{{application_layer{N}_humidity?}}` — отн. влажность, %
- `{{application_layer{N}_air_temp?}}` — t воздуха, °C
- `{{application_layer{N}_surface_temp?}}` — t поверхности, °C
- `{{application_layer{N}_dew_point?}}` — точка росы, °C
- `{{application_layer{N}_wet_film_min?}}` / `{{..._wet_film_max?}}` — толщина мокрого слоя (мин/макс), мкм
- `{{application_layer{N}_wet_film_range?}}` — готовая строка диапазона «мин–макс»
- `{{application_layer{N}_dry_film_min?}}` / `{{..._dry_film_max?}}` / `{{..._dry_film_mean?}}` — толщина сухого слоя (мин/макс/средняя), мкм
- `{{application_layer{N}_dry_film_range?}}` — готовая строка диапазона «мин–макс»
- `{{application_layer{N}_visual_control?}}` — ВИК
- `{{application_layer{N}_note?}}` — примечание
- `{{application_layer_count?}}` — число слоёв

### Дефекты/процесс (`process`)
- плоско: `{{process_items?}}` — текст «Несоответствие — Корректирующее действие», по строке на запись
- таблицей (повтор строки): ячейки `{{process.description}}` (Несоответствие), `{{process.action}}` (Корректирующее действие) в одной строке-шаблоне → клонируется по числу записей

### Рекомендации (`recommendations`)
- плоско: `{{recommendations_items?}}` — нумерованный текст «1. …\n2. …» (перенос → `<w:br/>`)
- списком/таблицей (повтор): `{{recommendations.text}}` — в строке таблицы (cloneRow) или в абзаце-пункте внутри `{{recommendations}}…{{/recommendations}}` (cloneBlock, настоящий список Word)

### Вывод (`conclusion`)
- плоско: `{{conclusion_text?}}` — нумерованный текст «1. …\n2. …», обязателен ≥1 пункт на отправке
- списком (повтор): `{{conclusion.text}}` внутри `{{conclusion}}…{{/conclusion}}` (cloneBlock) или строкой таблицы

### Комиссия (`commission`)
- плоско: `{{commission_items?}}` — текст «Организация — Должность — ФИО — Дата», по строке на члена
- таблицей (повтор строки): `{{commission.organization}}`, `{{commission.position}}`, `{{commission.name}}`, `{{commission.date}}` в одной строке-шаблоне

### Повторяемые группы — правила
- Точечная нотация `{{group.sub}}` = повторяемая группа `group` (имя = ключ блока), подполя = ключи itemFields (у списков строк подполе `text`). Без `?`.
- Строка таблицы: в ОДНОЙ строке только плейсхолдеры одной группы (cloneRow индексирует всю строку). Пустой список → строка удаляется (единственная строка → уйдёт вся таблица).
- Абзац-список: обернуть один абзац-пункт в `{{group}}…{{/group}}` (маркеры каждый в своём абзаце, движок их уберёт). Пустой список → регион удаляется.
- Плоские `{{block_items}}`/`{{conclusion_text}}` остаются как запасной вариант.

### Примечания (`notes`)
- `{{notes_text?}}` — примечания

### Комиссия (`commission`)
- `{{commission_items?}}` — список (строки: организация — должность — ФИО — дата)

### Фото (`photos`) — позже
Пока не проецируется. Планируемые ключи: `{{photos_photo{N}?}}` (картинка), `{{photos_photo{N}_caption?}}`, `{{photos_photo_count?}}`.

## Что дальше (инкремент 6)
Use-case генерации: по (тип, `application_layer_count`) выбирает нужный `.docx`, проектирует, рендерит.
Хранение/выбор шаблонов — по образцу Proposals (`tkp_template.xlsx` + сущность-шаблон). Проекция фото в `ImageValue`.
