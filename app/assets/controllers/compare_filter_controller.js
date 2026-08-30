import { Controller } from '@hotwired/stimulus';

/**
 * Сайдбар-фильтр compare-страницы: чекбоксы скрывают/показывают строки сравнения,
 * тумблер «Только различия» дополнительно прячет совпадающие строки (data-diff="0").
 * Состояние (видимые поля + режим) sticky в localStorage. По умолчанию все поля
 * включены, «только различия» выключен.
 */
export default class extends Controller {
    static targets = ['checkbox', 'row', 'onlyDiff'];
    static values = {
        storageKey: { type: String, default: 'compare:fields:Coating' },
        diffKey: { type: String, default: 'compare:onlyDiff:Coating' },
    };

    connect() {
        const stored = this._read();
        if (stored !== null) {
            this.checkboxTargets.forEach(cb => {
                cb.checked = stored.includes(cb.dataset.field);
            });
        }
        this.checkboxTargets.forEach(cb => cb.addEventListener('change', () => this._apply()));

        if (this.hasOnlyDiffTarget) {
            this.onlyDiffTarget.checked = this._readDiff();
            this.onlyDiffTarget.addEventListener('change', () => this._apply());
        }

        this._apply();
    }

    _apply() {
        const visible = new Set(
            this.checkboxTargets.filter(cb => cb.checked).map(cb => cb.dataset.field),
        );
        const onlyDiff = this.hasOnlyDiffTarget && this.onlyDiffTarget.checked;

        this.rowTargets.forEach(row => {
            const fieldVisible = visible.has(row.dataset.field);
            const diffOk = !onlyDiff || row.dataset.diff === '1';
            row.classList.toggle('d-none', !(fieldVisible && diffOk));
        });

        this._write([...visible]);
        this._writeDiff(onlyDiff);
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

    _readDiff() {
        try {
            return window.localStorage.getItem(this.diffKeyValue) === '1';
        } catch {
            return false;
        }
    }

    _writeDiff(onlyDiff) {
        window.localStorage.setItem(this.diffKeyValue, onlyDiff ? '1' : '0');
    }
}
