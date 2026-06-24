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

    add(event) {
        const id = event.params.id;
        if (!id) return;
        const ids = this._read();
        if (ids.includes(id)) return;
        if (ids.length >= this.maxValue) {
            alert(`Можно сравнить максимум ${this.maxValue} покрытия.`);
            return;
        }
        ids.push(id);
        this._write(ids);
        this._reflectButton(id, true);
    }

    remove(event) {
        const id = event.params.id;
        if (!id) return;
        const ids = this._read().filter(x => x !== id);
        this._write(ids);
        this._reflectButton(id, false);
    }

    clear() {
        this._write([]);
        this.element.querySelectorAll('[data-compare-id]').forEach(btn => {
            this._reflectButton(btn.dataset.compareId, false);
        });
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
        // Отметить уже добавленные кнопки.
        this.element.querySelectorAll('[data-compare-id]').forEach(btn => {
            this._reflectButton(btn.dataset.compareId, ids.includes(btn.dataset.compareId));
        });
    }

    _reflectButton(id, isInTray) {
        const btn = this.element.querySelector(`[data-compare-id="${id}"]`);
        if (!btn) return;
        btn.classList.toggle('btn-success', isInTray);
        btn.classList.toggle('btn-outline-success', !isInTray);
        btn.innerHTML = isInTray ? '<i class="bi bi-check2"></i>' : '<i class="bi bi-plus-lg"></i>';
        btn.title = isInTray ? 'Убрать из сравнения' : 'Добавить в сравнение';
        btn.dataset.action = isInTray
            ? 'click->compare-tray#remove'
            : 'click->compare-tray#add';
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
