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
 *   modalViewLink   — ссылка «Открыть страницу»
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
        'modalLayers',
        'modalLayersCount',
        'modalCompliance',
        'modalDescription',
        'modalViewLink',
        'modalEditLink',
        'modalDeleteLink',
        'modalEditSection',
    ];

    static values = {
        modalId: String,
        viewRoute: String,
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
        this.modalLayersCountTarget.textContent = data.layerCount;

        // Layers table
        const tbody = this.modalLayersTarget;
        tbody.innerHTML = '';
        (data.layers ?? []).forEach(layer => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-muted">${layer.position}</td>
                <td>
                    <span class="fw-semibold">${layer.coatingTitle}</span>
                    ${layer.isZincRich ? '<span class="badge text-bg-warning ms-1 small">Zn(R)</span>' : ''}
                </td>
                <td class="text-muted small">${layer.coatingBaseTitle}</td>
                <td class="text-end fw-semibold">${layer.dft}</td>
            `;
            tbody.appendChild(tr);
        });

        // Compliance
        const complianceEl = this.modalComplianceTarget;
        complianceEl.innerHTML = '';
        const compliance = data.compliance ?? [];
        if (compliance.length > 0) {
            compliance.forEach(entry => {
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-success';
                badge.textContent = entry.category + (entry.durability ? ' — ' + entry.durability : '');
                complianceEl.appendChild(badge);
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
        this.modalViewLinkTarget.href = this.viewRouteValue.replace('PLACEHOLDER', data.id);
        if (this.hasModalEditLinkTarget) {
            this.modalEditLinkTarget.href = this.editRouteValue.replace('PLACEHOLDER', data.id);
        }
        if (this.hasModalDeleteLinkTarget) {
            this.modalDeleteLinkTarget.href = this.deleteRouteValue.replace('PLACEHOLDER', data.id);
        }
    }

    _getModal() {
        const id = this.modalIdValue || 'coatingSystemModal';
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    }
}
