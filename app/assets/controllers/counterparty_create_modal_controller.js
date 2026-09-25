import { Controller } from '@hotwired/stimulus';

/**
 * Модалка создания контрагента (форма отчёта: заказчик/подрядчик). Открывается из typeahead по
 * «+ Создать»: собирает название + обязательный ИНН, POST на createUrl виджета-инициатора, по успеху
 * возвращает созданного контрагента обратно в тот виджет (requester.addCreated). Зеркалит идиому
 * color-create-modal ↔ coating-colors.
 */
export default class extends Controller {
    static targets = ['nameInput', 'tinInput', 'errorBox'];

    open(prefillName, requester) {
        this._requester = requester || null;
        this._hideError();
        this.nameInputTarget.value = (prefillName || '').trim();
        this.tinInputTarget.value = '';
        this._getModal().show();
        setTimeout(() => this.tinInputTarget.focus(), 200);
    }

    async submit() {
        this._hideError();

        if (!this._requester || !this._requester.createUrlValue) {
            this._showError('Не удалось определить адрес создания.');
            return;
        }

        const title = this.nameInputTarget.value.trim();
        const tin = this.tinInputTarget.value.trim();
        if (title === '') { this._showError('Укажите название.'); return; }
        if (tin === '') { this._showError('Укажите ИНН.'); return; }

        try {
            const resp = await fetch(this._requester.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ title, tin }),
            });
            // Ответы обёрнуты глобальным ResponseListener: успех {data:{...}}, ошибка {message}.
            const json = await resp.json();
            if (resp.status === 201) {
                this._requester.addCreated(json.data ?? json);
                this._getModal().hide();
                return;
            }
            this._showError(json.message || 'Не удалось создать контрагента.');
        } catch (e) {
            this._showError('Сетевая ошибка. Попробуйте ещё раз.');
        }
    }

    _showError(message) {
        this.errorBoxTarget.textContent = message;
        this.errorBoxTarget.classList.remove('d-none');
    }

    _hideError() {
        this.errorBoxTarget.classList.add('d-none');
        this.errorBoxTarget.textContent = '';
    }

    _getModal() {
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById('counterpartyCreateModal'));
    }
}
