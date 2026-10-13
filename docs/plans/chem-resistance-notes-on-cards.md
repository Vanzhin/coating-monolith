# Химстойкость: показ примечаний на карточках обратного поиска

Ветка: `fix/chem-resistance-lactic-acid-split` (продолжение; вторая задача).

## Задача

На странице «Химстойкость» (мультивыбор веществ → карточки покрытий, `_resistant_cards`)
вердикт-чип не показывал примечания оценки. Нужно: значок на чипе, если у покрытия для
выбранного вещества есть примечание, и раскрытие текста по клику/тапу.

Таблица химстойкости покрытия (`chem_resistance_edit`) примечания уже показывает (tooltip) —
её не трогаем.

## Решения (с разработчиком)

- Раскрытие — **popover по клику/тапу** (ноты бывают в несколько абзацев; тело скроллится).
- **Значок на едином чипе** (худший вердикт); в popover — список по веществам:
  вещество → его примечания (заголовок + текст). Значок виден, если есть несистемное примечание.

## Реализация

**Данные (граница контекстов соблюдена — Coatings берёт готовое из CR-запроса):**
- `App\ChemicalResistance\Application\DTO\AssessmentNoteDTO` (title, description, isSystem) —
  единый объектный DTO примечания (без array-shape). На него переведены и существующий
  `AssessmentRowDTO.notes`, и новые поля.
- `ResistantCoatingDTO.notes: list<AssessmentNoteDTO>` — источник `EffectiveAssessmentNotes::of()`
  в `GetResistantCoatingsQueryHandler`.
- `SubstanceVerdictDTO.notes: list<AssessmentNoteDTO>` — проброс `$rc->notes` в
  `GetCoatingsBySubstanceQueryHandler`.

**UI:**
- `_resistant_cards.html.twig`: если среди вердиктов есть несистемное примечание — кнопка-значок
  `bi-info-circle` на чипе + скрытый `<template>` с телом popover (по веществам). Разметку
  рендерит Twig (единственный источник), JS не дублирует.
- Stimulus `note_popover_controller.js`: навешивает `bootstrap.Popover` (trigger focus = клик/тап,
  html-контент из `<template>`, `customClass: note-popover`). Коннектится и на догруженных карточках.
- CSS `components/note-popover.css` (`@import` в app.css): ширина + скролл тела popover.

## Тесты / верификация

- TDD-якорь: `GetResistantCoatingsNotesTest` — примечание оценки доходит до `ResistantCoatingDTO`
  (RED→GREEN на реальной БД; в пустой test_db скипается, как и соседи).
- `AssessmentRowDTO.notes` переведён на DTO — `ListCoatingAssessmentsQueryHandlerTest` поправлен на
  объектный доступ.
- Гейты: style, phpstan (level 6), unit — зелёные. Целевой functional в test-стеке — без ошибок.
- Фронт (popover) — сборка `yarn dev` ОК; визуальный смоук — за разработчиком (браузер не
  автоматизировали).

Смоук-URL (dev): `/cabinet/coating/coating/by-substance?substanceIds[]=<id ACRYLONITRILE>` —
карточка «Литатанк Плюс» со значком; popover: ACRYLONITRILE → Прим. 4 + Прим. 6.
