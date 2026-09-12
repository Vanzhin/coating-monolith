# Консолидация поиска покрытий: SearchCoatings → GetPagedCoatings (бэклог)

Заметка на будущее (по указанию заказчика). НЕ делаем сейчас — отдельная задача-рефактор.

## Что сделать
- Убрать `readonly class SearchCoatingsQueryHandler implements QueryHandlerInterface`
  (`app/src/Coatings/Application/UseCase/Query/SearchCoatings/`) и перенести его логику в
  `app/src/Coatings/Application/UseCase/Query/GetPagedCoatings`.
- Цель — единый query-путь поиска/пагинации покрытий вместо двух параллельных.

## Что учесть при переносе
- Потребитель `SuggestAction` (`Coatings/Infrastructure/Controller/Coating/SuggestAction.php`)
  сейчас гоняет `SearchCoatingsQuery` и отдаёт item-shape
  `{id, title, base, dftMin, dftMax, mixingRatio}` (mixingRatio добавлен в Деплое 3 калькулятора).
  При консолидации сохранить этот shape (или адаптировать потребителя под GetPagedCoatings).
- Проверить прочих потребителей `SearchCoatingsQuery` (грепнуть) перед удалением.
- Регистрация хендлеров — через `implements QueryHandlerInterface` (как в проекте).

## Статус
Только записано. Реализация — когда дойдут руки, отдельной веткой/планом.
