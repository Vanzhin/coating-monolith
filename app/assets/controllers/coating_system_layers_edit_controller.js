import { Controller } from '@hotwired/stimulus';

/**
 * Локальное (без AJAX) редактирование состава слоёв системы покрытий.
 *
 * Add/Remove/Move меняют только DOM. При submit'е основной формы
 * сервер получит layers[i][coatingId] и layers[i][dft] в текущем DOM-порядке
 * и вызовет ReplaceLayersCommand, полностью заменяющую состав.
 *
 * Номер позиции в плитке — визуальный, пересчитывается локально после каждой мутации.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'appendCoating', 'appendDft', 'rowTemplate', 'addButton', 'addForm'];

    /** Раскрыть inline-пикер добавления слоя. */
    toggleAdd() {
        this.addFormTarget.classList.remove('d-none');
        if (this.hasAddButtonTarget) this.addButtonTarget.classList.add('d-none');
        this.addFormTarget.querySelector('select, input')?.focus?.();
    }

    /** Свернуть пикер добавления слоя. */
    cancelAdd() {
        this.addFormTarget.classList.add('d-none');
        if (this.hasAddButtonTarget) this.addButtonTarget.classList.remove('d-none');
        this.appendCoatingTarget.value = '';
        this.appendDftTarget.value = '';
    }

    /** ТСП-степпер −/+ (шаг из data-delta-param, clamp по min/max инпута). */
    stepDft(event) {
        const delta = parseInt(event.params.delta, 10) || 0;
        const row = event.currentTarget.closest('[data-coating-system-layers-edit-target="row"]');
        const input = row?.querySelector('[data-role="dft"]');
        if (!input) return;
        const min = parseInt(input.min, 10) || 1;
        const max = input.max ? parseInt(input.max, 10) : 9999;
        const next = (parseInt(input.value, 10) || 0) + delta;
        input.value = Math.max(min, Math.min(max, next));
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /** Перетаскивание слоя за grip (Pointer Events, без библиотек). Кнопки ↑/↓ — рядом,
        как fallback/доступность. touch-action:none на grip (CSS) не даёт жесту скроллить. */
    dragStart(event) {
        if (event.button != null && event.button !== 0) return; // только основная кнопка
        const row = event.currentTarget.closest('[data-coating-system-layers-edit-target="row"]');
        if (!row) return;
        event.preventDefault();

        this._dragRow = row;
        this._dragGrip = event.currentTarget;
        this._dragPointerId = event.pointerId;
        row.classList.add('lcard--dragging');

        this._dragGrip.setPointerCapture?.(event.pointerId);
        this._onDragMove = this._dragMove.bind(this);
        this._onDragEnd = this._dragEnd.bind(this);
        this._dragGrip.addEventListener('pointermove', this._onDragMove);
        this._dragGrip.addEventListener('pointerup', this._onDragEnd);
        this._dragGrip.addEventListener('pointercancel', this._onDragEnd);
    }

    _dragMove(event) {
        if (!this._dragRow) return;
        const y = event.clientY;
        const others = this.rowTargets.filter((r) => r !== this._dragRow);
        let target = null;
        for (const r of others) {
            const rect = r.getBoundingClientRect();
            if (y < rect.top + rect.height / 2) { target = r; break; }
        }
        if (target) {
            // вставить перед target, если ещё не там (иначе дёргается)
            if (target.previousElementSibling !== this._dragRow) {
                this.listTarget.insertBefore(this._dragRow, target);
            }
        } else if (this.listTarget.lastElementChild !== this._dragRow) {
            this.listTarget.appendChild(this._dragRow); // ниже всех
        }
    }

    _dragEnd() {
        if (this._dragGrip) {
            this._dragGrip.releasePointerCapture?.(this._dragPointerId);
            this._dragGrip.removeEventListener('pointermove', this._onDragMove);
            this._dragGrip.removeEventListener('pointerup', this._onDragEnd);
            this._dragGrip.removeEventListener('pointercancel', this._onDragEnd);
        }
        this._dragRow?.classList.remove('lcard--dragging');
        this._dragRow = null;
        this._dragGrip = null;
        this._renumber();
    }

    append() {
        const coatingId = this.appendCoatingTarget.value;
        const dft = this.appendDftTarget.value;
        const coatingTitle = this.appendCoatingTarget.options[this.appendCoatingTarget.selectedIndex]?.text ?? '';
        if (!coatingId || !dft) {
            return;
        }

        const fragment = this.rowTemplateTarget.content.cloneNode(true);
        const row = fragment.querySelector('[data-coating-system-layers-edit-target="row"]');
        row.querySelector('[data-role="coatingId"]').value = coatingId;
        row.querySelector('[data-role="dft"]').value = dft;
        row.querySelector('[data-role="title"]').textContent = coatingTitle;
        // Прокидываем покрытие в контрол цвета до вставки — на connect он подтянет /colors.
        const layerColor = row.querySelector('[data-controller~="layer-color"]');
        if (layerColor) {
            layerColor.setAttribute('data-layer-color-coating-id-value', coatingId);
        }
        this.listTarget.appendChild(fragment);

        this.appendCoatingTarget.value = '';
        this.appendDftTarget.value = '';
        this._renumber();
    }

    remove(event) {
        const row = event.currentTarget.closest('[data-coating-system-layers-edit-target="row"]');
        row.remove();
        this._renumber();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('[data-coating-system-layers-edit-target="row"]');
        const prev = row.previousElementSibling;
        if (prev) {
            row.parentNode.insertBefore(row, prev);
            this._renumber();
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-coating-system-layers-edit-target="row"]');
        const next = row.nextElementSibling;
        if (next) {
            row.parentNode.insertBefore(next, row);
            this._renumber();
        }
    }

    _renumber() {
        this.rowTargets.forEach((row, i) => {
            // Выбор инпутов по data-role, а НЕ по типу: у слоя теперь два hidden
            // (coatingId + colorId у layer-color), input[type=hidden] брал бы первый.
            const coatingIdInput = row.querySelector('[data-role="coatingId"]');
            if (coatingIdInput) {
                coatingIdInput.name = `layers[${i}][coatingId]`;
            }
            const dftInput = row.querySelector('[data-role="dft"]');
            if (dftInput) {
                dftInput.name = `layers[${i}][dft]`;
            }
            // Имя скрытого colorId держит сам layer-color по value `field`.
            const layerColor = row.querySelector('[data-controller~="layer-color"]');
            if (layerColor) {
                layerColor.setAttribute('data-layer-color-field-value', `layers[${i}][colorId]`);
            }
        });
    }
}
