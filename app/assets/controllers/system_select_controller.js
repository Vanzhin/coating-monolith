import { Controller } from '@hotwired/stimulus';

/**
 * Выбор системы покрытия поиском по названию. И в выпадающем списке, и в выбранном показывает состав:
 * слои (материал — ТСП), подложку, подготовку, среду, суммарную толщину — чтобы отличить тёзок и
 * видеть, что выбрал. Пишет hidden systemId. На правке — предвыбор (дообогащается запросом по названию).
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
            this._renderSelected(this.selectedValue);
            this._renderHidden(this.selectedValue.id);
            this._enrich(this.selectedValue);
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

    async _fetch(q) {
        if (q === '') return [];
        try {
            const u = new URL(this.searchUrlValue, window.location.origin);
            u.searchParams.set('q', q);
            const r = await fetch(u, { credentials: 'same-origin' });
            if (!r.ok) return [];
            const j = await r.json();
            return Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
        } catch (err) {
            return [];
        }
    }

    async _search(q) {
        // FTS-поиск систем требует ≥3 символов (SearchQuery.MIN_LENGTH) — короче не шлём.
        if (q.length < 3) {
            this.resultsTarget.innerHTML = q === '' ? '' : '<div class="form-text">Введите минимум 3 символа</div>';
            return;
        }
        this._seq += 1;
        const seq = this._seq;
        const items = await this._fetch(q);
        if (seq !== this._seq) return;
        this._renderResults(items);
    }

    /** Правка: по {id,title} тянем полную карточку (слои и т.д.) и заменяем предвыбор. */
    async _enrich(sel) {
        const items = await this._fetch(sel.title);
        const match = items.find(s => s.id === sel.id);
        if (match) this._renderSelected(match);
    }

    _renderResults(items) {
        if (items.length === 0) {
            this.resultsTarget.innerHTML = '<div class="text-body-secondary small mt-2">Ничего не найдено.</div>';
            return;
        }
        this.resultsTarget.innerHTML = '';
        items.forEach(s => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-100 text-start p-2 mb-1 rounded-3 bg-body border-0 sys-opt';
            btn.innerHTML = this._cardInner(s);
            btn.addEventListener('click', () => this._select(s));
            this.resultsTarget.appendChild(btn);
        });
    }

    _select(s) {
        this._renderSelected(s);
        this._renderHidden(s.id);
        this.resultsTarget.innerHTML = '';
        this.inputTarget.value = '';
    }

    clear() {
        this._renderSelected(null);
        this._renderHidden('');
    }

    _cardInner(s) {
        const layers = Array.isArray(s.layers) ? s.layers : [];
        const layersHtml = layers.length
            ? '<ul class="small text-body-secondary mb-1 mt-1 ps-3">'
                + layers.map(l => `<li>${this._esc(l.title)} — ${Number(l.dft)} мкм</li>`).join('')
                + '</ul>'
            : '';
        const meta = [s.substrate, s.prep, s.environment].filter(Boolean).join(' · ');
        const total = s.dft ? ` · Σ ${Number(s.dft)} мкм` : '';
        return `<div class="fw-semibold">${this._esc(s.title)}</div>${layersHtml}`
            + `<div class="small text-body-secondary">${this._esc(meta)}${total}</div>`;
    }

    _renderSelected(s) {
        if (s && s.id) {
            this.selectedTarget.innerHTML = `<div class="p-2 rounded-3 bg-body-tertiary">${this._cardInner(s)}`
                + '<button type="button" class="btn btn-sm btn-link p-0 mt-1 text-decoration-none" data-action="system-select#clear">сменить</button></div>';
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
