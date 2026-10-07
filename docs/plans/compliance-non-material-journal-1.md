# Не материальный акт — журналы инструктажей. Деплой 1: шаблон на требовании + чистка списания

> Парный план: [compliance-non-material-journal-2.md](compliance-non-material-journal-2.md) (Деплой 2 — захват инструктаж-полей и авто-заполнение журнала). Этот деплой самодостаточен и шиппится отдельно.

**Goal:** у каждого требования — свой загружаемый Word-шаблон документа; скачивание карточки/акта берёт шаблон из требования (фолбэк — текущая СИЗ-карточка). Для не материальных актов убираем UI списания. Базовая автоподстановка (ФИО/должность/отдел/дата/перечень нормы) уже работает проектором — отдельной задачи не требует.

**Architecture:** шаблон хранится в едином файловом реестре (`Shared/File`, прямой `FileStorage::store` — docx не в белом списке staged-зоны) с привязкой owner=id требования; `Requirement` несёт `?string $templateFileId`; `DownloadCardAction` резолвит шаблон требования через `FileStorage` (локальный путь) и рендерит им вместо захардкоженного пути.

**Материальный (СИЗ) флоу по поведению НЕ меняем.** Правки затрагивают общие файлы (Requirement/SaveRequirement/DownloadCardAction/форма), но аддитивны и для материального — no-op: пустой `templateFileId` → фолбэк на прежнюю `requirement_card.docx`, UI списания у СИЗ остаётся. Не материальный тип уже ветвится по всему потоку (из аудита); единственная не-материально-специфичная UI-правка здесь — спрятать списание. Функц.-тесты СИЗ в гейте фиксируют неизменность материального.

**Tech Stack:** PHP 8.3+, Symfony, Doctrine ORM (XML), `Shared/File` (StoredFile/FilePurpose/FileStorage), движок шаблонов `Shared/Infrastructure/Service/TemplateRendering` + `RenderData`/`TemplateFile`, PHPUnit. Гейты — `./run check`.

## Global Constraints

- Коммиты — только по явному апруву (git ведёт пользователь). Шаги «Commit» — точки, где спросить.
- Доменные инварианты → `App\Shared\Infrastructure\Exception\AppException` (рус.). VO — `final readonly`.
- Хендлеры — `implements CommandHandlerInterface`/`QueryHandlerInterface` (не атрибут); алиасы в `services.yaml`.
- Шаблоны UI — копировать разметку ближайшего аналога, не изобретать CSS. Авторизация — через `ComplianceAccessControl`, не `#[IsGranted]`.
- Миграции идемпотентные (`IF NOT EXISTS`). Юнит на хосте, функц. в контейнере (`./run check`).
- Бинарные .docx-журналы — зона пользователя. Код даёт плейсхолдеры/конфиг; сами журналы (пожарный, ОТ) пользователь кладёт в `Resources/templates` после согласования ключей.

## Review Focus

- **Фолбэк шаблона:** требование без загруженного шаблона → скачивание использует старую `requirement_card.docx` без ошибки (материальные СИЗ-требования не ломаются). Task 3/5.
- **Промах файла:** `templateFileId` указывает на удалённый/отсутствующий файл → не падаем 500, фолбэк на дефолт. Task 5.
- **Тип при создании:** загрузка шаблона доступна для обоих типов (или ограничить не материальным?) — по умолчанию доступно всем, пустой = дефолт. Task 4.
- **Чистка списания:** у не материального акта в UI нет «Списать»/«Действующие позиции», но материальный СИЗ-акт их сохраняет. Task 6.
- **Доступ к файлу шаблона:** скачивание/замена шаблона — только `canManage()` (как остальной Compliance). Task 4/5.

---

## Файловая структура

```
app/src/Compliance/
  Domain/File/RequirementTemplatePurpose.php      — FilePurpose для docx-шаблона требования (НОВОЕ)
  Domain/Aggregate/Requirement/Requirement.php     — +?string $templateFileId (+ getter/setter)
  Application/UseCase/Command/SaveRequirement/SaveRequirementCommand.php     — +templateUpload (UploadedFile|null) / +removeTemplate(bool)
  Application/UseCase/Command/SaveRequirement/SaveRequirementCommandHandler.php — store/replace/remove файла
  Application/DTO/.../RequirementDTO(Transformer)   — +templateFileName для гидрации формы
  Infrastructure/Controller/Requirements/EditAction.php  — читает upload из запроса
  Infrastructure/Controller/Document/DownloadCardAction.php — резолв шаблона требования
  Infrastructure/Database/ORM/Aggregate/Requirement.Requirement.orm.xml — +template_file_id
app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php — +column
app/src/Shared/Infrastructure/Templates/admin/compliance/requirements/form.html.twig — поле загрузки шаблона
app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig — прячем списание у не материального
```

---

## Task 1: FilePurpose для шаблона требования

**Files:** Create `app/src/Compliance/Domain/File/RequirementTemplatePurpose.php`; Test `app/tests/Unit/Compliance/Domain/File/RequirementTemplatePurposeTest.php`.

**Interfaces:** Produces enum `RequirementTemplatePurpose: string implements FilePurpose` с `case Template='requirement_template'`; `storagePrefix()='compliance/requirement_template'`, `key()='compliance.requirement_template'`, `constraints()` = `new FileConstraints(15*1024*1024, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])` (docx + xlsx — движок умеет оба).

- [ ] Step 1: тест — `Template->key()` и `constraints()->allowedMimeTypes()` содержат docx-mime. Run `vendor/bin/phpunit .../RequirementTemplatePurposeTest.php` → FAIL.
- [ ] Step 2: реализация по образцу `RequirementScanPurpose.php`.
- [ ] Step 3: тест → PASS.
- [ ] Step 4: Commit (по апруву): «Compliance: FilePurpose docx-шаблона требования».

## Task 2: Поле templateFileId на Requirement + ORM + миграция

**Files:** Modify `Requirement.php` (поле `?string $templateFileId`, геттер `getTemplateFileId()`, сеттер `setTemplateFileId(?string)`), `Requirement.Requirement.orm.xml` (`<field name="templateFileId" column="template_file_id" nullable="true" length="36"/>`), Create migration `Version<ts>.php` (`ALTER TABLE compliance_requirement ADD COLUMN IF NOT EXISTS template_file_id VARCHAR(36) DEFAULT NULL`); Test — расширить юнит агрегата `Requirement`.

**Interfaces:** Produces `Requirement::getTemplateFileId(): ?string`, `setTemplateFileId(?string): void`. Immutable-id правило соблюдаем (id в ctor). Поле опционально, по умолчанию null.

- [ ] Step 1: тест — новое требование `getTemplateFileId() === null`; `setTemplateFileId('x')` → `'x'`. FAIL.
- [ ] Step 2: добавить поле/геттер/сеттер + ORM + миграция (test_db мигрировать в контейнере).
- [ ] Step 3: тест → PASS.
- [ ] Step 4: Commit: «Compliance: у требования появился свой файл-шаблон документа (template_file_id)».

## Task 3: Приём/замена/удаление шаблона при сохранении требования

**Files:** Modify `SaveRequirementCommand.php` (+`?UploadedFile $templateUpload`, +`bool $removeTemplate`), `SaveRequirementCommandHandler.php`, `RequirementDTO`/`RequirementDTOTransformer` (+`?string $templateFileName`/`hasTemplate`), Test — `app/tests/Functional/Compliance/Application/UseCase/SaveRequirementTemplateTest.php`.

**Interfaces:**
- Consumes: `FileStorage::store(RequirementTemplatePurpose::Template, $requirement->getId(), $upload)` (прямой store — docx не проходит staged allow-list), `FileStorage::removeByOwner(...)`.
- Produces: при `templateUpload !== null` — store новый файл, `requirement->setTemplateFileId($stored->id())` (предыдущий — `removeByOwner` до store); при `removeTemplate` — `removeByOwner` + `setTemplateFileId(null)`. Пустой сабмит — не трогаем.

- [ ] Step 1: тест — сохранить требование с docx-upload → `getTemplateFileId()` не null, файл в сторадже; повторный upload заменяет; `removeTemplate` обнуляет. FAIL.
- [ ] Step 2: реализация (store после создания требования — id уже есть; порядок: сохранить требование, затем файл, затем проставить id и сохранить снова — либо store до add, id генерируется в maker). Ruling-момент: owner=id требования, id известен до флаша.
- [ ] Step 3: тест → PASS (миграция test_db применена).
- [ ] Step 4: Commit: «Compliance: загрузка/замена Word-шаблона в форме требования».

## Task 4: Поле загрузки шаблона в форме требования

**Files:** Modify `EditAction.php` (читать `request->files->get('template')`, `request->request->getBoolean('remove_template')`, пробросить в команду; гидрация имени при правке), `form.html.twig` (блок загрузки файла — копировать разметку staged-upload/обычного file-input из ближайшего аналога; показать текущее имя + чекбокс «удалить»). Ассеты не трогаем, если переиспользуем существующий паттерн загрузки.

- [ ] Step 1: контроллер-тест — POST формы требования с файлом → редирект, требование получило `templateFileId`. (Либо покрыто Task 3 на уровне команды; здесь — тонкий тест экшена.)
- [ ] Step 2: реализация экшена + Twig (поле только когда это осмысленно; по умолчанию доступно).
- [ ] Step 3: `cd app && yarn dev` если менялись ассеты (вероятно нет).
- [ ] Step 4: Commit: «Compliance: поле Word-шаблона в форме требования (загрузка/замена/удаление)».

## Task 5: DownloadCardAction резолвит шаблон требования

**Files:** Modify `DownloadCardAction.php`; Test — расширить `app/tests/Functional/Compliance/Infrastructure/Controller/CardDownloadControllerTest.php`.

**Interfaces:**
- Consumes: `FileStorage::get($id)`/`toLocalTempFile($id)`/`localPath($id)`, `$requirement->getTemplateFileId()`, фолбэк `$this->cardTemplatePath`.
- Produces: если у требования есть `templateFileId` и файл существует → `new TemplateFile(<локальный путь из FileStorage>)`; иначе `new TemplateFile($this->cardTemplatePath)`. (Для S3-адаптера — `toLocalTempFile` + удалить после рендера; для local — `localPath`.)

- [ ] Step 1: тест — требование со своим шаблоном скачивается им (проверяем по характерному плейсхолдеру/контенту); требование без шаблона — дефолтной карточкой; битый `templateFileId` → фолбэк без 500. FAIL.
- [ ] Step 2: реализация (инъектировать `FileStorage`).
- [ ] Step 3: тест → PASS.
- [ ] Step 4: Commit: «Compliance: карточка/акт печатается по шаблону своего требования (фолбэк — дефолт)».

## Task 6: Чистка UI списания для не материального акта

**Files:** Modify `app/src/Shared/Infrastructure/Templates/admin/compliance/person/issue.html.twig` (обернуть «Списать» ~78-81 и «Действующие позиции»/«Списать» ~126-166 в `{% if requirementType == 'material' %}`); проверить `IssueAction` уже фильтрует записи без количества. Фронт-тестами PHP не покрываем — визуальная проверка.

- [ ] Step 1: правка Twig (условие по типу требования, переменная уже есть в шаблоне — `requirementType`/`o.type`).
- [ ] Step 2: прогнать существующие функц.-тесты акта (не материальный акт по-прежнему оформляется/подписывается).
- [ ] Step 3: браузерный смоук: у акта процедуры нет «Списать»/«Действующие позиции», у СИЗ — есть.
- [ ] Step 4: Commit: «Compliance: у не материального акта убрали UI списания (списания у процедур нет)».

## Финал Деплоя 1

- `./run check` зелёный.
- Деплой-заметка: миграция `template_file_id` на проде. Пользователь загружает journal-.docx в требования процедур (пожарный/ОТ) — базовые поля (`{{employee_fio}}`, `{{position_title}}`, `{{department_title}}`, `{{document_date}}`, `{{items.*}}`) заполнятся сразу; инструктаж-специфика — вручную в .docx до Деплоя 2.
- Дальше — [Деплой 2](compliance-non-material-journal-2.md): структурный захват инструктаж-полей + авто-заполнение.
