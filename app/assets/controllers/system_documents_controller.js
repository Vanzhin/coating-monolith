import { Controller } from '@hotwired/stimulus';
import { openReferencePreview } from '../reference_helpers';

/**
 * Ленивая подгрузка документов системы в её превью-фрагмент: фетчит url (документы системы) и
 * рисует чипы (значок + название, просроченные — жёлтым). Клик открывает превью документа поверх.
 * Если документов нет / ошибка — блок прячется.
 *
 * Values: url (документы системы), documentPreviewRoute (шаблон URL превью документа).
 * Targets: block (весь блок), list (контейнер чипов).
 */
export default class extends Controller {
    static values = { url: String, documentPreviewRoute: String };
    static targets = ['block', 'list'];

    async connect() {
        try {
            const response = await fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const json = await response.json();
            const items = json.data?.items ?? json.items ?? [];
            if (0 === items.length) {
                this._hide();
                return;
            }
            items.forEach(doc => this.listTarget.appendChild(this._chip(doc)));
        } catch {
            this._hide();
        }
    }

    _hide() {
        if (this.hasBlockTarget) {
            this.blockTarget.classList.add('d-none');
        }
    }

    _chip(doc) {
        const chip = document.createElement('button');
        chip.type = 'button';
        // Просроченный — жёлтым (warning), не ярко-красным.
        chip.className = 'btn btn-sm d-inline-flex align-items-center gap-1 '
            + (doc.isExpired ? 'btn-outline-warning' : 'btn-outline-secondary');
        chip.title = (doc.kindLabel || 'Документ') + (doc.isExpired ? ' · просрочен' : '');

        const icon = document.createElement('i');
        icon.className = 'bi bi-file-earmark-text';
        chip.appendChild(icon);

        const label = document.createElement('span');
        label.textContent = doc.title;
        chip.appendChild(label);

        if (doc.id && this.hasDocumentPreviewRouteValue) {
            chip.addEventListener('click', () => {
                openReferencePreview(this.documentPreviewRouteValue, doc.id);
            });
        }

        return chip;
    }
}
