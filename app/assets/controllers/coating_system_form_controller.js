import { Controller } from '@hotwired/stimulus';

/**
 * Управляет формой системы покрытий:
 *  - динамическим добавлением/удалением строк слоёв,
 *  - перестановкой слоёв вверх/вниз,
 *  - перенумерацией индексов name-атрибутов после любого изменения.
 */
export default class extends Controller {
    static targets = ['layersList', 'layerRow', 'layerTemplate'];

    addLayer() {
        if (!this.hasLayerTemplateTarget) return;
        const template = this.layerTemplateTarget;
        const clone = template.content.cloneNode(true);
        const index = this.layerRowTargets.length;
        clone.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace(/__INDEX__/g, String(index));
        });
        this.layersListTarget.appendChild(clone);
        this._renumber();
    }

    removeLayer(event) {
        const row = event.currentTarget.closest('[data-coating-system-form-target="layerRow"]');
        if (!row) return;
        row.remove();
        this._renumber();
    }

    moveUp(event) {
        const row = event.currentTarget.closest('[data-coating-system-form-target="layerRow"]');
        if (!row) return;
        const prev = row.previousElementSibling;
        if (prev) {
            this.layersListTarget.insertBefore(row, prev);
            this._renumber();
        }
    }

    moveDown(event) {
        const row = event.currentTarget.closest('[data-coating-system-form-target="layerRow"]');
        if (!row) return;
        const next = row.nextElementSibling;
        if (next) {
            this.layersListTarget.insertBefore(next, row);
            this._renumber();
        }
    }

    /**
     * Перенумеровывает name-атрибуты всех строк слоёв.
     * Заменяет индекс вида layers[N][...] на актуальный порядковый номер.
     */
    _renumber() {
        this.layerRowTargets.forEach((row, i) => {
            row.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/layers\[\d+\]/g, `layers[${i}]`);
            });
        });
    }
}
