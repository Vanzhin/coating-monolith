# Общий «pending»-компонент кнопок (анти-дабл-клик на серверные действия)

## Контекст

Кнопки/карточки, что по клику фетчат серверный фрагмент и показывают модалку, не гасятся
на время запроса. При тормозящей сети юзер кликает несколько раз → несколько параллельных
fetch → несколько модалок одного и того же объекта.

Источник бага — универсальный `entity_preview_controller` (карточки покрытий/систем/
документов в списках): `open()` зовёт `openReferencePreview` без всякого guard. У
`coating_preview_loader` есть кустарный флаг `this._loading`, но он локальный и кнопку
визуально не гасит. Защита размазана и неполная.

## Развилки (согласовано)

- **Охват сейчас**: примитив `withPending` + дедуп в `openFragmentModal` + перевод трёх
  загрузчиков превью (`entity_preview`, `coating_preview_loader`,
  `coating_system_preview_loader`). Остальные fetch-контроллеры и сабмиты форм подключаем
  по мере надобности отдельно — не в этой задаче.
- **Визуал**: гасим триггер (`disabled` для `<button>`/`<input>`, иначе `aria-disabled` +
  `pointer-events:none`), `aria-busy`, класс `.is-pending` (приглушение + `cursor:wait`).
  Без новых цветов/иконок — минимальный нейтральный стиль (см. [[feedback_reuse_styles]]).

## Дизайн

### Примитив `assets/pending.js`

```js
export async function withPending(el, asyncFn) {
    if (!el || el.dataset.pending === '1') return;      // реэнтранси-guard: повтор игнор
    el.dataset.pending = '1';
    const native = el.tagName === 'BUTTON' || el.tagName === 'INPUT';
    if (native) el.disabled = true; else el.setAttribute('aria-disabled', 'true');
    el.setAttribute('aria-busy', 'true');
    el.classList.add('is-pending');
    try {
        return await asyncFn();
    } finally {
        delete el.dataset.pending;
        if (native) el.disabled = false; else el.removeAttribute('aria-disabled');
        el.removeAttribute('aria-busy');
        el.classList.remove('is-pending');
    }
}
```

- Идемпотентна к типу элемента: работает и на `<button>`, и на карточке/чипе/строке.
- `dataset.pending` — логический guard; `.is-pending { pointer-events:none }` — не даёт
  повторно кликнуть по не-кнопке. Восстановление гарантировано в `finally` (в т.ч. при
  броске из `asyncFn`).

### Пояс + подтяжки: дедуп в `reference_helpers.js::openFragmentModal`

Module-level `Set` URL-ов «в полёте». Если фрагмент этого URL уже грузится — второй вызов
no-op. Закрывает окно двойного клика даже там, где триггер не обёрнут в `withPending`
(и когда два разных триггера ведут на один фрагмент).

```js
const inFlightFragments = new Set();
export async function openFragmentModal(url, onModal = null) {
    if (inFlightFragments.has(url)) return;
    inFlightFragments.add(url);
    try { /* существующий fetch + show */ }
    finally { inFlightFragments.delete(url); }
}
```

Guard покрывает только async-окно загрузки — после `.show()` карточка уже под модалкой и
недоступна для клика, легитимный второй показ не блокируем.

### CSS `assets/styles/components/pending.css`

```css
.is-pending { opacity: .65; cursor: wait; pointer-events: none; }
```

Подключить через `@import` в `assets/styles/app.css`. `<button disabled>` и так гасится
Bootstrap — этот класс добавляет неблокирующий визуал для не-кнопок.

### Ретрофит контроллеров

Все три используют `event.currentTarget` как триггер:

- `entity_preview_controller.open` → `withPending(event.currentTarget, () => openReferencePreview(...))`.
- `coating_system_preview_loader_controller.open` → так же (сохранить `stopPropagation`).
- `coating_preview_loader_controller.open` → обернуть в `withPending`, **выпилить** кустарный
  `this._loading` (примитив его заменяет); сохранить `stopPropagation` и highlight-хук.

## Файлы

- `app/assets/pending.js` — новый (примитив).
- `app/assets/reference_helpers.js` — дедуп в `openFragmentModal`.
- `app/assets/styles/components/pending.css` — новый; `@import` в `app/assets/styles/app.css`.
- `app/assets/controllers/entity_preview_controller.js`
- `app/assets/controllers/coating_preview_loader_controller.js` (убрать `_loading`)
- `app/assets/controllers/coating_system_preview_loader_controller.js`

## Верификация

JS-тест-харнесса в проекте нет — по конвенции (см. [[feedback_no_phpunit_on_frontend]]):

1. `cd app && yarn dev` — сборка без ошибок.
2. Браузер, Network throttling (Slow 3G): спам-клик по карточке покрытия в списке →
   ровно одна модалка; триггер приглушён и не кликается, пока грузится; после загрузки —
   снова активен. Проверить также чип системы внутри превью покрытия и слой внутри
   превью системы.
3. Проверить, что при ошибке сети триггер разблокируется (не «залипает»).

## Отложено (отдельные задачи)

- Ретрофит остальных fetch-контроллеров (color_create_modal, surface_treatment_modal,
  layer_color, coating_colors, …).
- Анти-дабл-сабмит форм (confirm_form, coating_form save) и «загрузить ещё» infinite_list.
