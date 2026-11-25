# Единая страница создания и редактирования путевого листа

## Контекст и решение

Создание и редактирование уже используют общий `_form.html.twig`, но признак `creating` переключает порядок секций, разметку периода, заправок и поездок, расположение одометра и кнопки. Нужна одна страница, а не два интерфейса внутри общего файла.

Эталон — текущая компактная страница редактирования, реализованная по `waybill-edit-compact-trips.md`: секции «Период» → «Заправки» → «Без поездок» → «Поездки», конечный одометр в поездках, компактные заправки, раскрывающиеся поездки с маршрутной линией и нижняя кнопка сохранения. Создание принимает тот же вид и поведение.

Команды и обработчики создания/обновления не объединять. Маршруты, права, проверка начальных показаний автомобиля при создании и оптимистичная блокировка по версии при редактировании сохраняются. Миграции и изменения домена не нужны.

## Шаги

1. Сделать `cabinet/waybill/sheet/form.html.twig` полноценной страницей на основе текущего `_form.html.twig` и компактной ветки редактирования. Оба action контроллера рендерят её; `create.html.twig`, `edit.html.twig` и старый `_form.html.twig` удалить после переноса.
2. Убрать визуальные ветвления по `creating` из `_fuel_section`, `_odometer_fields`, `_refueling_row`, `_trip_row`. Оставить только служебные различия: заголовок и URL возврата, CSRF-токен, `version` при редактировании, `initialOdometer`/`initialFuel` и `refresh_readings` при создании, сообщение о ранее сохранённых вложенных заправках.
3. Нормализовать данные для страницы: название и госномер автомобиля, имя водителя, начальные показания, доступные марки и марка топлива по умолчанию. При создании источник — текущий `VehicleDTO`, при редактировании — данные `Waybill`; не подменять показания листа текущими показаниями автомобиля. Добавить имя водителя в `VehicleDTO` из уже загруженного автомобиля, без нового запроса.
4. Сохранить существующие POST-сценарии: атомарное создание, обновление по версии, `fill_route` без сохранения, `refresh_readings` без потери введённых данных, повторный показ ошибок и CSRF. Не вводить универсальную save-команду и не переносить бизнес-правила в контроллер или Twig.
5. Обновить функциональные тесты: одинаковые секции и компактная разметка в обоих режимах, водитель и автомобиль на создании и редактировании, скрытые поля каждого режима, динамические шаблоны поездок/заправок/отсутствий. Сохранить и при необходимости уточнить существующие проверки сохранения, ошибок, автозаполнения, конфликта версии и обновления начальных показаний; удалить утверждения, которые намеренно закрепляли старое различие интерфейсов.
6. Прогнать Twig/PHP/JS-проверки и собрать ассеты. Проверить HTTP-сценарии создания и редактирования на итоговых шаблонах, включая раскрывающиеся поездки и работу без JS через нативный `<details>`.

## Основные файлы

- `app/src/Waybills/Infrastructure/Controller/Waybill/WaybillController.php`
- `app/src/Waybills/Infrastructure/Controller/Waybill/WaybillFormMapper.php`
- `app/src/Waybills/Application/DTO/VehicleDTO.php`
- `app/src/Waybills/Application/UseCase/Query/GetVehicle/GetVehicleQueryHandler.php`
- `app/src/Shared/Infrastructure/Templates/cabinet/waybill/sheet/form.html.twig` и существующие частичные шаблоны строк/секций
- `app/tests/Functional/Waybills/Infrastructure/Controller/WaybillControllerTest.php`
- затронутые unit-тесты DTO/запроса и `app/tests/Unit/Waybills/Infrastructure/Stimulus/waybill_form_controller.test.cjs`, если поведение JS изменится

## Проверки

- `cd app && vendor/bin/phpunit tests/Functional/Waybills/Infrastructure/Controller/WaybillControllerTest.php`
- `cd app && vendor/bin/phpunit tests/Unit/Waybills tests/Functional/Waybills`
- `cd app && node --test tests/Unit/Waybills/Infrastructure/Stimulus/waybill_form_controller.test.cjs`
- `cd app && bin/console lint:twig src/Shared/Infrastructure/Templates/cabinet/waybill/sheet`
- `cd app && yarn dev`

Без отдельного разрешения не коммитить и не пушить.
