import { Controller } from '@hotwired/stimulus';

/**
 * Модалка создания цвета (открывается из coating-colors по «+ Создать»).
 * Два режима:
 *  - «Из RAL» — поиск/сетка образцов RAL Classic; клик подставляет название (заготовка,
 *    редактируемо) + код RAL; hex выводит бэк из эталона.
 *  - «Кастомный» — название + hex через нативный color-input, без RAL.
 * По успеху POST /color добавляет цвет в typeahead coating-colors и закрывается.
 */
export default class extends Controller {
    static targets = [
        'nameInput', 'ralSearch', 'ralGrid', 'ralSection', 'customSection',
        'customHex', 'selectedRalLabel', 'errorBox', 'modeRadio',
    ];

    static values = {
        createUrl: { type: String, default: '/cabinet/coating/color' },
        ralUrl: { type: String, default: '/cabinet/coating/color/ral' },
    };

    connect() {
        this._ralDebounce = null;
        this._selectedRal = null;
    }

    open(prefillName, requester = null) {
        // requester — контроллер, куда вернуть созданный цвет (layer-color).
        // Без него по умолчанию цвет уходит в coating-colors (форма покрытия).
        this._requester = requester && typeof requester.addColor === 'function' ? requester : null;
        this._resetForm();
        this.nameInputTarget.value = (prefillName || '').trim();
        this._setMode('ral');
        this._loadRal('');
        this._getModal().show();
    }

    onModeChange() {
        const checked = this.modeRadioTargets.find(r => r.checked);
        this._setMode(checked ? checked.value : 'ral');
    }

    onRalSearch() {
        if (this._ralDebounce) clearTimeout(this._ralDebounce);
        this._ralDebounce = setTimeout(() => this._loadRal(this.ralSearchTarget.value), 250);
    }

    selectRal(event) {
        const btn = event.currentTarget;
        this._selectedRal = { code: btn.dataset.code, name: btn.dataset.name, hex: btn.dataset.hex };

        this.ralGridTarget.querySelectorAll('.ral-palette-item').forEach(el => el.classList.remove('active'));
        btn.classList.add('active');

        // Название — заготовка из RAL, редактируемо.
        this.nameInputTarget.value = btn.dataset.name;
        this.selectedRalLabelTarget.innerHTML =
            `<span class="color-swatch color-swatch--lg" style="background:${this._escape(btn.dataset.hex)}"></span>`
            + `${this._escape(btn.dataset.code)}`;
    }

    async submit() {
        this._hideError();

        const name = this.nameInputTarget.value.trim();
        if (name === '') {
            this._showError('Укажите название цвета.');
            return;
        }

        const mode = this._currentMode();
        let body;
        if (mode === 'ral') {
            if (!this._selectedRal) {
                this._showError('Выберите цвет из каталога RAL или переключитесь на «Кастомный».');
                return;
            }
            body = { name, ral: this._selectedRal.code };
        } else {
            body = { name, hex: this.customHexTarget.value };
        }

        try {
            const resp = await fetch(this.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            // Ответы обёрнуты глобальным ResponseListener: успех — {data: {...}}, ошибка — {message}.
            const json = await resp.json();
            if (resp.status === 201) {
                this._pushToTypeahead(json.data ?? json);
                this._getModal().hide();
                return;
            }
            this._showError(json.message || 'Не удалось создать цвет.');
        } catch (e) {
            this._showError('Сетевая ошибка. Попробуйте ещё раз.');
        }
    }

    async _loadRal(query) {
        let list = [];
        try {
            const url = new URL(this.ralUrlValue, window.location.origin);
            if (query && query.trim() !== '') url.searchParams.set('q', query.trim());
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (resp.ok) {
                // Ответы обёрнуты глобальным ResponseListener в {data: …}.
                const json = await resp.json();
                list = Array.isArray(json) ? json : (Array.isArray(json.data) ? json.data : []);
            }
        } catch (e) {
            // Сеть недоступна — сетка останется пустой.
        }

        this.ralGridTarget.innerHTML = list.map(ral => `
            <button type="button" class="ral-palette-item"
                    data-action="click->color-create-modal#selectRal"
                    data-code="${this._escape(ral.code)}"
                    data-name="${this._escape(ral.name)}"
                    data-hex="${this._escape(ral.hex)}">
                <span class="color-swatch" style="background:${this._escape(ral.hex)}"></span>
                <span class="ral-palette-item__meta">
                    <span class="ral-palette-item__name">${this._escape(ral.name)}</span>
                    <span class="ral-palette-item__code">${this._escape(ral.code)}</span>
                </span>
            </button>
        `).join('');
        this._selectedRal = null;
        this.selectedRalLabelTarget.textContent = '';
    }

    _pushToTypeahead(color) {
        if (this._requester) {
            this._requester.addColor(color);
            return;
        }
        const el = document.querySelector('[data-controller~="coating-colors"]');
        const app = window.Stimulus;
        if (el && app) {
            const ctrl = app.getControllerForElementAndIdentifier(el, 'coating-colors');
            if (ctrl) ctrl.addColor(color);
        }
    }

    _currentMode() {
        const checked = this.modeRadioTargets.find(r => r.checked);
        return checked ? checked.value : 'ral';
    }

    _setMode(mode) {
        this.modeRadioTargets.forEach(r => { r.checked = r.value === mode; });
        this.ralSectionTarget.classList.toggle('d-none', mode !== 'ral');
        this.customSectionTarget.classList.toggle('d-none', mode !== 'custom');
    }

    _resetForm() {
        this.nameInputTarget.value = '';
        this.ralSearchTarget.value = '';
        this.ralGridTarget.innerHTML = '';
        this.selectedRalLabelTarget.textContent = '';
        this.customHexTarget.value = '#888888';
        this._selectedRal = null;
        this._hideError();
    }

    _showError(message) {
        this.errorBoxTarget.textContent = message;
        this.errorBoxTarget.classList.remove('d-none');
    }

    _hideError() {
        this.errorBoxTarget.classList.add('d-none');
        this.errorBoxTarget.textContent = '';
    }

    _escape(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        // innerHTML не экранирует кавычки — значения идут в атрибуты (data-*, style).
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    _getModal() {
        return window.bootstrap.Modal.getOrCreateInstance(document.getElementById('colorCreateModal'));
    }
}
