import { Controller } from '@hotwired/stimulus';

/**
 * Range-фасет фильтра списка покрытий: чипы-пресеты + auto-submit.
 *
 * Источник истины — два hidden input'а (fromInput / toInput). Backend
 * читает их напрямую. Клик по чипу устанавливает от/до из data-attr'ов
 * и submit'ит форму; «Любая» очищает hidden'ы и submit'ит.
 */
export default class extends Controller {
    static targets = ['fromInput', 'toInput'];

    preset(event) {
        const btn = event.currentTarget;
        this.fromInputTarget.value = btn.dataset.from ?? '';
        this.toInputTarget.value = btn.dataset.to ?? '';
        this._submit();
    }

    reset() {
        this.fromInputTarget.value = '';
        this.toInputTarget.value = '';
        this._submit();
    }

    _submit() {
        const form = this.element.closest('form');
        if (form) form.submit();
    }
}
