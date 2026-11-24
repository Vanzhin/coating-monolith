import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['country', 'city', 'cityType', 'street', 'house', 'enterprise', 'errorBox'];

    open(prefillCity, requester) {
        this._requester = requester || null;
        this._hideError();
        this.countryTarget.value = 'Россия';
        this.cityTarget.value = (prefillCity || '').trim();
        this.cityTypeTarget.value = 'city';
        for (const target of ['street', 'house', 'enterprise']) this[`${target}Target`].value = '';
        this._modal().show();
        setTimeout(() => this.cityTarget.focus(), 200);
    }

    async submit() {
        this._hideError();
        if (!this._requester || !this._requester.createUrlValue) {
            this._showError('Не удалось определить адрес создания.');
            return;
        }
        const body = {};
        for (const field of ['country', 'city', 'cityType', 'street', 'house', 'enterprise']) body[field] = this[`${field}Target`].value.trim();

        try {
            const response = await fetch(this._requester.createUrlValue, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            const json = await response.json();
            if (response.status === 201) {
                this._requester.addCreated(json.data ?? json);
                this._modal().hide();
                return;
            }
            this._showError(json.message || 'Не удалось создать точку маршрута.');
        } catch (error) {
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

    _modal() {
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById('locationCreateModal'));
    }
}
