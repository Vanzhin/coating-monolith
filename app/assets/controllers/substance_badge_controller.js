import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { id: String };

    connect() {
        this.element.addEventListener('click', () => {
            const modalSelector = this.element.getAttribute('data-bs-target');
            if (!modalSelector) return;
            const modal = document.querySelector(modalSelector);
            if (modal) modal.setAttribute('data-highlight-substance-id', this.idValue);
        });
    }
}
