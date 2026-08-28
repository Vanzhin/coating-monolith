import { Controller } from '@hotwired/stimulus';

/**
 * Tray для набора покрытий к сравнению. Состояние — localStorage по ключу
 * 'compare:Coating': массив {id, title}. Лимит — 4. Открывает
 * /cabinet/coating/coating/compare?ids=... Выбранные показываются removable-чипами.
 */
export default class extends Controller {
    static targets = ['bar', 'count', 'openBtn', 'chips'];
    static values = {
        storageKey: { type: String, default: 'compare:Coating' },
        compareUrl: { type: String, default: '/cabinet/coating/coating/compare' },
        max:        { type: Number, default: 4 },
    };

    connect() {
        this._sync();
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
        const title = cb.dataset.compareTitle || id;

        const items = this._read();
        if (cb.checked) {
            if (items.some((x) => x.id === id)) return;
            if (items.length >= this.maxValue) {
                cb.checked = false;
                alert(`Можно сравнить максимум ${this.maxValue} покрытия.`);
                return;
            }
            items.push({ id, title });
        } else {
            const i = items.findIndex((x) => x.id === id);
            if (i === -1) return;
            items.splice(i, 1);
        }
        this._write(items);
    }

    // Убрать покрытие из сравнения по крестику на чипе.
    remove(event) {
        const id = event.currentTarget.dataset.id;
        this._write(this._read().filter((x) => x.id !== id));
    }

    clear() {
        this._write([]);
    }

    open() {
        const ids = this._read().map((x) => x.id);
        if (ids.length < 2) {
            alert('Выберите минимум 2 покрытия.');
            return;
        }
        window.location.href = `${this.compareUrlValue}?ids=${ids.join(',')}`;
    }

    _sync() {
        const items = this._read();
        const ids = items.map((x) => x.id);
        if (this.hasCountTarget) this.countTarget.textContent = String(items.length);
        if (this.hasBarTarget) this.barTarget.classList.toggle('d-none', items.length === 0);
        if (this.hasOpenBtnTarget) this.openBtnTarget.disabled = items.length < 2;
        this._renderChips(items);
        this.element.querySelectorAll('[data-compare-id]').forEach((cb) => {
            cb.checked = ids.includes(cb.dataset.compareId);
        });
    }

    _renderChips(items) {
        if (!this.hasChipsTarget) return;
        this.chipsTarget.innerHTML = '';
        items.forEach((item) => {
            const chip = document.createElement('span');
            chip.className = 'c-chip';
            const label = document.createElement('span');
            label.className = 'c-chip-label';
            label.textContent = item.title || item.id; // textContent — без XSS
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'c-chip-x';
            btn.setAttribute('aria-label', 'Убрать из сравнения');
            btn.dataset.action = 'compare-tray#remove';
            btn.dataset.id = item.id;
            btn.textContent = '×';
            chip.append(label, btn);
            this.chipsTarget.append(chip);
        });
    }

    _read() {
        try {
            const raw = window.localStorage.getItem(this.storageKeyValue);
            const arr = raw ? JSON.parse(raw) : [];
            // Обратная совместимость со старым форматом (массив строк-id).
            return arr.map((x) => (typeof x === 'string' ? { id: x, title: x } : x)).filter((x) => x && x.id);
        } catch {
            return [];
        }
    }

    _write(items) {
        window.localStorage.setItem(this.storageKeyValue, JSON.stringify(items));
        this._sync();
    }
}
