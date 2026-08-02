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
    static targets = ['list', 'row', 'position', 'appendCoating', 'appendDft', 'rowTemplate'];

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
        row.querySelector('input[type="number"]').value = dft;
        row.querySelector('[data-role="title"]').textContent = coatingTitle;
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
            const positionEl = row.querySelector('[data-coating-system-layers-edit-target="position"]');
            if (positionEl) {
                positionEl.textContent = String(i + 1);
            }
            const coatingIdInput = row.querySelector('input[type="hidden"]');
            if (coatingIdInput) {
                coatingIdInput.name = `layers[${i}][coatingId]`;
            }
            const dftInput = row.querySelector('input[type="number"]');
            if (dftInput) {
                dftInput.name = `layers[${i}][dft]`;
            }
        });
    }
}
