import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['country', 'city', 'cityType', 'street', 'house', 'enterprise', 'errorBox', 'title', 'searchPanel', 'formPanel', 'searchInput', 'createButton', 'backButton', 'submitButton'];

    open(prefillCity, requester) {
        this._requester = requester || null;
        this._createDraftStarted = true;
        this._resetForm(prefillCity);
        this._showCreatePanel(false);
        this._modal().show();
        setTimeout(() => this.cityTarget.focus(), 200);
    }

    openPicker(requester) {
        this._requester = requester;
        this._createDraftStarted = false;
        this._resetForm('');
        this.searchInputTarget.value = '';
        this.searchInputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this._showSearchPanel();
        this._modal().show();
        setTimeout(() => this.searchInputTarget.focus(), 200);
    }

    showCreate() {
        if (!this._createDraftStarted) {
            this.cityTarget.value = this.searchInputTarget.value.trim();
            this._createDraftStarted = true;
        }
        this._showCreatePanel(true);
        this.cityTarget.focus();
    }

    showSearch() {
        this._showSearchPanel();
        this.searchInputTarget.focus();
    }

    selectLocation(event) {
        if (!this._requester || this.searchPanelTarget.hidden) return;
        this._requester.addCreated(event.detail);
        this._modal().hide();
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

    _resetForm(prefillCity) {
        this._hideError();
        this.countryTarget.value = 'Россия';
        this.cityTarget.value = (prefillCity || '').trim();
        this.cityTypeTarget.value = 'city';
        for (const target of ['street', 'house', 'enterprise']) this[`${target}Target`].value = '';
    }

    _showSearchPanel() {
        this._hideError();
        this.titleTarget.textContent = 'Найти или создать точку';
        this.searchPanelTarget.hidden = false;
        this.formPanelTarget.hidden = true;
        this.createButtonTarget.hidden = false;
        this.backButtonTarget.hidden = true;
        this.submitButtonTarget.hidden = true;
    }

    _showCreatePanel(withBack) {
        this.titleTarget.textContent = 'Новая точка маршрута';
        this.searchPanelTarget.hidden = true;
        this.formPanelTarget.hidden = false;
        this.createButtonTarget.hidden = true;
        this.backButtonTarget.hidden = !withBack;
        this.submitButtonTarget.hidden = false;
    }

    _showError(message) {
        this.errorBoxTarget.classList.remove('d-none');
        this.errorBoxTarget.textContent = message;
    }

    _hideError() {
        this.errorBoxTarget.classList.add('d-none');
        this.errorBoxTarget.textContent = '';
    }

    _modal() {
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById('locationCreateModal'));
    }
}
