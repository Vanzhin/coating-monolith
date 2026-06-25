import { Controller } from '@hotwired/stimulus';

/**
 * Tray для набора покрытий к сравнению. Состояние — localStorage по ключу 'compare:Coating'.
 * Лимит — 4. Открывает /cabinet/coating/coating/compare?ids=...
 */
export default class extends Controller {
    static targets = ['bar', 'count', 'openBtn'];
    static values = {
        storageKey: { type: String, default: 'compare:Coating' },
        compareUrl: { type: String, default: '/cabinet/coating/coating/compare' },
        max:        { type: Number, default: 4 },
    };

    connect() {
        this._sync();
        // Реагировать на изменения из других вкладок.
        window.addEventListener('storage', this._onStorage = (e) => {
            if (e.key === this.storageKeyValue) this._sync();
        });
    }

    disconnect() {
        if (this._onStorage) window.removeEventListener('storage', this._onStorage);
    }

    toggle(event) {
        const cb = event.target;
        const id = cb.dataset.compareId;
        if (!id) return;

        const ids = this._read();
        if (cb.checked) {
            if (ids.includes(id)) return;
            if (ids.length >= this.maxValue) {
                cb.checked = false;
                alert(`Можно сравнить максимум ${this.maxValue} покрытия.`);
                return;
            }
            ids.push(id);
        } else {
            const i = ids.indexOf(id);
            if (i === -1) return;
            ids.splice(i, 1);
        }
        this._write(ids);
    }

    clear() {
        this._write([]);
    }

    open() {
        const ids = this._read();
        if (ids.length < 2) {
            alert('Выберите минимум 2 покрытия.');
            return;
        }
        window.location.href = `${this.compareUrlValue}?ids=${ids.join(',')}`;
    }

    _sync() {
        const ids = this._read();
        if (this.hasCountTarget) this.countTarget.textContent = String(ids.length);
        if (this.hasBarTarget) this.barTarget.classList.toggle('d-none', ids.length === 0);
        if (this.hasOpenBtnTarget) this.openBtnTarget.disabled = ids.length < 2;
        this.element.querySelectorAll('[data-compare-id]').forEach(cb => {
            cb.checked = ids.includes(cb.dataset.compareId);
        });
    }

    _read() {
        try {
            const raw = window.localStorage.getItem(this.storageKeyValue);
            return raw ? JSON.parse(raw) : [];
        } catch {
            return [];
        }
    }

    _write(ids) {
        window.localStorage.setItem(this.storageKeyValue, JSON.stringify(ids));
        this._sync();
    }
}
