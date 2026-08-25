import { Controller } from '@hotwired/stimulus';

/**
 * Повторяемые строки-ссылки на системы в форме документа. Каждая строка — клон <template>,
 * внутри async-typeahead (Stimulus подхватит клон автоматически). Минимум одна строка.
 */
export default class extends Controller {
    static targets = ['list', 'template', 'row'];

    connect() {
        if (this.rowTargets.length === 0) {
            this.add();
        }
    }

    add() {
        const node = this.templateTarget.content.firstElementChild.cloneNode(true);
        this.listTarget.appendChild(node);
    }

    remove(event) {
        const row = event.target.closest('[data-document-references-target="row"]');
        if (row && this.rowTargets.length > 1) {
            row.remove();
        }
    }
}
