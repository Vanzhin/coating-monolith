import { Controller } from '@hotwired/stimulus';
import { ZERO_UUID, fetchTitlesByIds, openReferencePreview } from '../reference_helpers';

/**
 * Заполняет единую модалку #documentModal данными карточки документа (data-payload).
 * Привязанные объекты (системы/покрытия) — кросс-контекст: имена тянем by-ids из Coatings,
 * клик по имени открывает серверный фрагмент-модалку превью поверх (стек Bootstrap).
 *
 * Values: modalId, editRoute (шаблон с плейсхолдером id),
 *   byIdsSystem/byIdsCoating, previewSystem/previewCoating (шаблоны URL с плейсхолдером id).
 */
export default class extends Controller {
    static targets = [
        'modalTitle', 'modalKind', 'modalStatus', 'modalMeta', 'modalSubject',
        'modalDescription', 'modalReferences', 'modalUpdatedAt', 'modalDownload',
        'modalEditLink', 'previewHost',
    ];
    static values = {
        modalId: String,
        editRoute: String,
        byIdsSystem: String,
        byIdsCoating: String,
        previewSystem: String,
        previewCoating: String,
    };

    open(event) {
        const payload = JSON.parse(event.currentTarget.dataset.payload);
        this._fill(payload);
        this._modal().show();
    }

    _fill(data) {
        this.modalTitleTarget.textContent = data.title;
        this.modalKindTarget.textContent = data.kindLabel;

        const status = this.modalStatusTarget;
        status.className = 'badge fw-normal';
        if (!data.expiresAt) {
            status.classList.add('text-bg-secondary');
            status.textContent = 'Бессрочный';
        } else if (data.isExpired) {
            status.classList.add('text-bg-danger');
            status.textContent = `Просрочен до ${data.expiresAt}`;
        } else {
            status.classList.add('text-bg-success');
            status.textContent = `Действует до ${data.expiresAt}`;
        }

        this.modalMetaTarget.textContent = [data.issuerTitle, data.issuedAt, data.testStandard].filter(Boolean).join(' · ');
        this.modalSubjectTarget.textContent = data.subject ?? '';

        const desc = this.modalDescriptionTarget;
        if (data.description) {
            desc.textContent = data.description;
            desc.closest('.modal-doc-description').classList.remove('d-none');
        } else {
            desc.closest('.modal-doc-description').classList.add('d-none');
        }

        this.modalUpdatedAtTarget.textContent = data.updatedAt ?? '';

        const download = this.modalDownloadTarget;
        if (data.downloadUrl) {
            download.href = data.downloadUrl;
            download.classList.remove('d-none');
        } else {
            download.classList.add('d-none');
        }

        if (this.hasModalEditLinkTarget) {
            this.modalEditLinkTarget.href = this.editRouteValue.replace(ZERO_UUID, data.id);
        }

        this._fillReferences(data.references ?? []);
    }

    async _fillReferences(refs) {
        const el = this.modalReferencesTarget;
        const block = el.closest('.modal-doc-references');
        el.innerHTML = '';

        if (0 === refs.length) {
            block.classList.add('d-none');
            return;
        }
        block.classList.remove('d-none');
        refs.forEach(ref => el.appendChild(this._refRow(ref)));

        const groups = { coating_system: [], coating: [] };
        refs.forEach(ref => { if (groups[ref.type]) groups[ref.type].push(ref.id); });

        for (const [type, ids] of Object.entries(groups)) {
            if (0 === ids.length) {
                continue;
            }
            const endpoint = 'coating' === type ? this.byIdsCoatingValue : this.byIdsSystemValue;
            const titles = await fetchTitlesByIds(endpoint, ids);
            el.querySelectorAll(`[data-ref-type="${type}"]`).forEach(chip => {
                const title = titles.get(chip.dataset.refId);
                const label = chip.querySelector('[data-role="refTitle"]');
                // Имя объекта; если не резолвится (объект удалён) — остаётся тип.
                label.textContent = title || chip.title;
                if (title) {
                    chip.title = title;
                }
            });
        }
    }

    _refRow(ref) {
        // Чип как у покрытий в системах: иконка типа + название. Система — bi-layers,
        // покрытие — bi-square. Имя подтягивается by-ids. Клик → превью.
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1';
        chip.dataset.refType = ref.type;
        chip.dataset.refId = ref.id;
        chip.title = ref.typeLabel || ('coating' === ref.type ? 'Покрытие' : 'Система');

        const icon = document.createElement('i');
        icon.className = 'coating' === ref.type ? 'bi bi-square' : 'bi bi-layers';
        chip.appendChild(icon);

        const label = document.createElement('span');
        label.dataset.role = 'refTitle';
        label.textContent = '…';
        chip.appendChild(label);

        chip.addEventListener('click', () => this._openRefPreview(ref.type, ref.id));

        return chip;
    }

    async _openRefPreview(type, id) {
        const template = 'coating' === type ? this.previewCoatingValue : this.previewSystemValue;
        await openReferencePreview(this.previewHostTarget, template, id);
    }

    _modal() {
        const id = this.modalIdValue || 'documentModal';
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    }
}
