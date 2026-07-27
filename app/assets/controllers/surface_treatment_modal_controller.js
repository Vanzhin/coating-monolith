import { Controller } from '@hotwired/stimulus';

/**
 * Управляет Bootstrap-модалкой для inline-создания подготовки поверхности.
 *
 * Values:
 *   endpoint          — URL API POST (напр. /api/surface-treatments)
 *   treatmentSelect   — id основного <select name="surfaceTreatmentId">
 *   substrateSelect   — id <select name="substrate"> (для преселекта scope)
 *
 * Targets:
 *   descriptionInput  — <textarea> описания
 *   codeInput         — <input> кода
 *   standardInput     — <input> стандарта
 *   scopeCheckbox     — <input type="checkbox"> scope (множество)
 *   errorBox          — блок отображения ошибки
 */
export default class extends Controller {
    static targets = ['descriptionInput', 'codeInput', 'standardInput', 'scopeCheckbox', 'errorBox'];

    static values = {
        endpoint: String,
        treatmentSelect: String,
        substrateSelect: String,
    };

    open() {
        this._resetForm();
        this._preselectScope();
        this._getModal().show();
    }

    async submit() {
        this._hideError();

        const description = this.descriptionInputTarget.value.trim();
        const code = this.codeInputTarget.value.trim() || null;
        const standard = this.standardInputTarget.value.trim() || null;
        const substrateScope = this.scopeCheckboxTargets
            .filter(cb => cb.checked)
            .map(cb => cb.value);

        const body = {
            description,
            code,
            standardCode: standard,
            substrateScope,
        };

        try {
            const response = await fetch(this.endpointValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            const data = await response.json();

            if (!response.ok) {
                this._showError(data.message ?? 'Ошибка при сохранении');
                return;
            }

            this._addOptionToSelect(data);
            this._getModal().hide();
        } catch (e) {
            this._showError('Сетевая ошибка. Попробуйте ещё раз.');
        }
    }

    _resetForm() {
        this.descriptionInputTarget.value = '';
        this.codeInputTarget.value = '';
        this.standardInputTarget.value = '';
        this.scopeCheckboxTargets.forEach(cb => { cb.checked = false; });
        this._hideError();
    }

    _preselectScope() {
        const substrateEl = document.getElementById(this.substrateSelectValue);
        if (!substrateEl) return;
        const substrate = substrateEl.value;
        if (!substrate) return;
        this.scopeCheckboxTargets.forEach(cb => {
            cb.checked = cb.value === substrate;
        });
    }

    _addOptionToSelect(data) {
        const select = document.getElementById(this.treatmentSelectValue);
        if (!select) return;
        const option = document.createElement('option');
        option.value = data.id;
        option.textContent = data.title ?? data.description;
        option.dataset.substrateScope = JSON.stringify(data.substrateScope ?? []);
        select.appendChild(option);
        option.selected = true;
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
        return window.bootstrap.Modal.getOrCreateInstance(
            document.getElementById('surfaceTreatmentModal')
        );
    }
}
