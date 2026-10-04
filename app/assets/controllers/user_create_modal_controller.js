import { Controller } from '@hotwired/stimulus';

/**
 * Модалка создания пользователя платформы (форма профиля сотрудника). Открывается из typeahead по
 * «+ Создать»: собирает email, POST на createUrl виджета-инициатора, по успеху возвращает созданного
 * юзера обратно в тот виджет (requester.addCreated). Зеркалит counterparty-create-modal.
 */
export default class extends Controller {
    static targets = ['emailInput', 'errorBox'];

    open(prefillEmail, requester) {
        this._requester = requester || null;
        this._hideError();
        this.emailInputTarget.value = (prefillEmail || '').trim();
        this._getModal().show();
        setTimeout(() => this.emailInputTarget.focus(), 200);
    }

    async submit() {
        this._hideError();

        if (!this._requester || !this._requester.createUrlValue) {
            this._showError('Не удалось определить адрес создания.');
            return;
        }

        const email = this.emailInputTarget.value.trim();
        if (email === '') { this._showError('Укажите email.'); return; }

        try {
            const resp = await fetch(this._requester.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ email }),
            });
            // Ответы обёрнуты глобальным ResponseListener: успех {data:{...}}, ошибка {message}.
            const json = await resp.json();
            if (resp.status === 201) {
                this._requester.addCreated(json.data ?? json);
                this._getModal().hide();
                return;
            }
            this._showError(json.message || 'Не удалось создать пользователя.');
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
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById('userCreateModal'));
    }
}
