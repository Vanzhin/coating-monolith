# Консолидация поиска покрытий: SearchCoatings по стилю GetPagedCoatings

## Что сделано (ветка refactor/search-coatings-paged)

Решение заказчика уточнилось по ходу: НЕ сливать в один хендлер, а оставить отдельный
лёгкий `SearchCoatings`, но переписать его один-в-один по стилю `GetPagedCoatings`.
Разница между ними — только в весе данных, всё остальное одинаково.

- `SearchCoatingsQuery` принимает `CoatingsFilter $filter` (как `GetPagedCoatingsQuery`),
  вместо прежних `q`/`limit`.
- `SearchCoatingsQueryResult` — result-класс `{CoatingSuggestDTO[] $coatings, Pager $pager}`
  (зеркалит `GetPagedCoatingsQueryResult`). Хендлер больше не возвращает `array`.
- `SearchCoatingsQueryHandler` структурно повторяет `GetPagedCoatingsQueryHandler`
  (`findByFilter($filter)` → items → `Pager` из фильтра). Отличие ровно в весе:
  строит лёгкий `CoatingSuggestDTO` (id, готовый title, base, dft, mixingRatio) вместо
  полного `CoatingDTO` и НЕ обогащает подсветкой веществ.
- `CoatingSuggestDTO` — новый лёгкий DTO строки typeahead без тяжёлых связей.
- Потребители `SuggestAction` и `ListApiAction` собирают `CoatingsFilter(search, pager)`
  и потребляют result. Suggest отдаёт `{items, page, hasMore}`; публичный API — прежний
  shape `{id, title, base, dftMin, dftMax}`.
- Фронт `async_typeahead_controller.js` — инфинит-скролл выпадающего списка: по
  `dropdown:scroll` у дна тянет следующую страницу и дописывает элементы в конец
  (createListHTML + insertAdjacentHTML, скролл не сбрасывается). Эндпоинты без `hasMore`
  работают как раньше — одной страницей.

## Проверки
`./run check style phpstan` — OK. `SuggestActionTest` 4/4, `ListApiActionTest` 4/4. `yarn dev` — OK.

## Статус
Реализовано, зелёное. Осталось глазами проверить сам скролл-догруз в браузере (нужно >1 страницы, лимит 10).
