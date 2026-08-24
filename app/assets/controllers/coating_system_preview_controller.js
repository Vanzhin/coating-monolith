import { Controller } from '@hotwired/stimulus';

/**
 * Заполняет единственную Bootstrap-модалку #coatingSystemModal данными
 * из data-атрибутов карточки системы покрытий.
 *
 * Использование:
 *   <div data-controller="coating-system-preview"
 *        data-coating-system-preview-modal-id-value="coatingSystemModal">
 *     ...карточки с data-action="click->coating-system-preview#open"
 *        data-payload="<json>"...
 *   </div>
 *
 * Targets:
 *   modalTitle      — <h5> заголовок модалки
 *   modalSubstrate  — блок субстрата
 *   modalTreatment  — блок подготовки поверхности
 *   modalDft        — блок суммарного NDFT
 *   modalLayers     — <tbody> таблицы слоёв
 *   modalLayersCount — счётчик слоёв
 *   modalCompliance — блок compliance-плашек
 *   modalDescription — блок описания
 *   modalEditLink   — ссылка «Редактировать»
 *   modalDeleteLink — ссылка «Удалить»
 *   modalEditSection — блок admin-actions (скрываем если не admin)
 */
export default class extends Controller {
    static targets = [
        'modalTitle',
        'modalSubstrate',
        'modalTreatment',
        'modalDft',
        'modalAppTime',
        'modalLayers',
        'modalLayersCount',
        'modalCompliance',
        'modalDescription',
        'modalEditLink',
        'modalDeleteLink',
        'modalEditSection',
    ];

    static values = {
        modalId: String,
        editRoute: String,
        deleteRoute: String,
    };

    open(event) {
        const trigger = event.currentTarget;
        const payload = JSON.parse(trigger.dataset.payload);

        this._fill(payload);
        this._getModal().show();
    }

    _fill(data) {
        this.modalTitleTarget.textContent = data.title;
        this.modalSubstrateTarget.textContent = data.substrateTitle;
        this.modalTreatmentTarget.textContent = data.treatment;
        this.modalDftTarget.textContent = data.totalDft + ' мкм';

        // Мин. время нанесения — может быть null для легаси-систем без +20-точки у слоя.
        if (data.minApplicationTime) {
            this.modalAppTimeTarget.textContent = data.minApplicationTime;
            this.modalAppTimeTarget.closest('.modal-apptime-col').classList.remove('d-none');
        } else {
            this.modalAppTimeTarget.closest('.modal-apptime-col').classList.add('d-none');
        }

        this.modalLayersCountTarget.textContent = data.layerCount;

        // Layers list — выделяем чип-пилюлей только название покрытия (клик открывает
        // полную модалку покрытия поверх), dft остаётся обычным текстом рядом.
        const container = this.modalLayersTarget;
        container.innerHTML = '';
        (data.layers ?? []).forEach(layer => {
            const row = document.createElement('div');

            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'btn btn-sm btn-outline-secondary rounded-pill';
            chip.textContent = layer.coatingTitle;
            if (layer.coatingId) {
                chip.dataset.coatingId = layer.coatingId;
                chip.dataset.action = 'click->coating-preview-loader#open';
            }

            row.appendChild(chip);
            row.appendChild(document.createTextNode(` ${layer.dft} мкм`));
            if (layer.colorHex) {
                const swatch = document.createElement('span');
                swatch.className = 'color-swatch ms-1';
                swatch.style.background = layer.colorHex;
                swatch.title = layer.colorLabel || layer.colorName || '';
                row.appendChild(swatch);
                row.appendChild(document.createTextNode(layer.colorLabel || layer.colorName || ''));
            }
            container.appendChild(row);
        });

        // Compliance
        const complianceEl = this.modalComplianceTarget;
        complianceEl.innerHTML = '';
        const compliance = data.compliance ?? [];
        if (compliance.length > 0) {
            const groups = new Map();
            compliance.forEach(entry => {
                const key = entry.standardTitle || entry.standard;
                if (!groups.has(key)) {
                    groups.set(key, []);
                }
                groups.get(key).push(entry.label);
            });
            groups.forEach((labels, standardTitle) => {
                const row = document.createElement('div');
                row.className = 'd-flex flex-wrap align-items-center gap-2 w-100';
                const name = document.createElement('span');
                name.className = 'text-body-secondary small';
                name.textContent = standardTitle + ':';
                row.appendChild(name);
                labels.forEach(label => {
                    const badge = document.createElement('span');
                    badge.className = 'badge text-bg-success fw-normal';
                    badge.textContent = label;
                    row.appendChild(badge);
                });
                complianceEl.appendChild(row);
            });
            complianceEl.closest('.modal-compliance-block').classList.remove('d-none');
        } else {
            complianceEl.closest('.modal-compliance-block').classList.add('d-none');
        }

        // Description
        const descEl = this.modalDescriptionTarget;
        if (data.description) {
            descEl.textContent = data.description;
            descEl.closest('.modal-description-block').classList.remove('d-none');
        } else {
            descEl.closest('.modal-description-block').classList.add('d-none');
        }

        // Links
        if (this.hasModalEditLinkTarget) {
            this.modalEditLinkTarget.href = this.editRouteValue.replace('00000000-0000-0000-0000-000000000000', data.id);
        }
        if (this.hasModalDeleteLinkTarget) {
            this.modalDeleteLinkTarget.href = this.deleteRouteValue.replace('00000000-0000-0000-0000-000000000000', data.id);
        }
    }

    _getModal() {
        const id = this.modalIdValue || 'coatingSystemModal';
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    }
}
