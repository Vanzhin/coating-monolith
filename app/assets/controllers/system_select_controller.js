import { Controller } from '@hotwired/stimulus';

/**
 * Выбор системы покрытия ПОИСКОМ ПО НАЗВАНИЮ. Карточка варианта показывает слои/толщину, подложку,
 * подготовку, среду — чтобы выбрать нужную из тёзок. Пишет hidden systemId. На правке — предвыбор
 * + «сменить». Обычный input + fetch + список карточек (без Tagify).
 */
export default class extends Controller {
    static targets = ['input', 'results', 'selected'];
    static values = {
        searchUrl: { type: String, default: '' },
        hiddenName: { type: String, default: 'systemId' },
        selected: { type: Object, default: {} },
    };

    connect() {
        this._timer = null;
        this._seq = 0;
        this.inputTarget.addEventListener('input', this._onInput.bind(this));

        if (this.selectedValue && this.selectedValue.id) {
            this._select(this.selectedValue.id, this.selectedValue.title);
        } else {
            this._renderSelected(null);
        }
    }

    disconnect() {
        if (this._timer) clearTimeout(this._timer);
    }

    _onInput() {
        if (this._timer) clearTimeout(this._timer);
        this._timer = setTimeout(() => this._search(this.inputTarget.value.trim()), 250);
    }

    async _search(q) {
        if (q === '') {
            this.resultsTarget.innerHTML = '';
            return;
        }
        this._seq += 1;
        const seq = this._seq;
        let items = [];
        try {
            const u = new URL(this.searchUrlValue, window.location.origin);
            u.searchParams.set('q', q);
            const r = await fetch(u, { credentials: 'same-origin' });
            if (r.ok) {
                const j = await r.json();
                items = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
            }
        } catch (err) {
            // сеть недоступна
        }
        if (seq !== this._seq) return;
        this._renderResults(items);
    }

    _renderResults(items) {
        if (items.length === 0) {
            this.resultsTarget.innerHTML = '<div class="text-body-secondary small mt-2">Ничего не найдено.</div>';
            return;
        }
        this.resultsTarget.innerHTML = '';
        items.forEach(s => {
            const meta = [`${Number(s.layers)} сл. · ${Number(s.dft)} мкм`, s.substrate, s.prep, s.environment]
                .filter(Boolean).join(' · ');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-100 text-start p-2 mb-1 rounded-3 bg-body border-0 sys-opt';
            btn.innerHTML = `<div class="fw-semibold">${this._esc(s.title)}</div>`
                + `<div class="small text-body-secondary">${this._esc(meta)}</div>`;
            btn.addEventListener('click', () => this._select(s.id, s.title));
            this.resultsTarget.appendChild(btn);
        });
    }

    _select(id, title) {
        this._renderSelected({ id, title });
        this._renderHidden(id);
        this.resultsTarget.innerHTML = '';
        this.inputTarget.value = '';
    }

    clear() {
        this._renderSelected(null);
        this._renderHidden('');
    }

    _renderSelected(sel) {
        if (sel && sel.id) {
            this.selectedTarget.innerHTML = `<span class="badge text-bg-primary">${this._esc(sel.title)}</span> `
                + '<button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-action="system-select#clear">сменить</button>';
        } else {
            this.selectedTarget.innerHTML = '<span class="text-body-secondary small">Система не выбрана.</span>';
        }
    }

    _renderHidden(id) {
        this.element.querySelectorAll('input.system-select-hidden').forEach(el => el.remove());
        if (id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = this.hiddenNameValue;
            input.value = id;
            input.className = 'system-select-hidden';
            this.element.appendChild(input);
        }
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
}
