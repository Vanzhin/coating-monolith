import { Controller } from '@hotwired/stimulus';

/**
 * Сайдбар-фильтр compare-страницы: чекбоксы скрывают/показывают строки сравнения.
 * Состояние видимых полей хранится в localStorage по ключу 'compare:fields:Coating'
 * (sticky между визитами). По умолчанию все включены.
 */
export default class extends Controller {
    static targets = ['checkbox', 'row'];
    static values = {
        storageKey: { type: String, default: 'compare:fields:Coating' },
    };

    connect() {
        const stored = this._read();
        if (stored !== null) {
            this.checkboxTargets.forEach(cb => {
                cb.checked = stored.includes(cb.dataset.field);
            });
        }
        this.checkboxTargets.forEach(cb => cb.addEventListener('change', () => this._apply()));
        this._apply();
    }

    _apply() {
        const visible = new Set(
            this.checkboxTargets.filter(cb => cb.checked).map(cb => cb.dataset.field),
        );
        this.rowTargets.forEach(row => {
            row.classList.toggle('d-none', !visible.has(row.dataset.field));
        });
        this._write([...visible]);
    }

    _read() {
        try {
            const raw = window.localStorage.getItem(this.storageKeyValue);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    _write(visible) {
        window.localStorage.setItem(this.storageKeyValue, JSON.stringify(visible));
    }
}
