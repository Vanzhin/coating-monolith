import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Выбор системы покрытия ПО ПОКРЫТИЯМ: вводишь покрытия (typeahead по каталогу) → показываются
 * системы, содержащие любое из них, с подложкой/средой/подготовкой → выбираешь. Пишет hidden
 * systemId. На правке — предвыбранная система показана, «сменить» открывает подбор заново.
 */
export default class extends Controller {
    static targets = ['coatingInput', 'results', 'selected'];
    static values = {
        coatingSuggestUrl: { type: String, default: '' },
        systemsUrl: { type: String, default: '' },
        hiddenName: { type: String, default: 'systemId' },
        selected: { type: Object, default: {} },
    };

    connect() {
        this.tagify = new Tagify(this.coatingInputTarget, {
            whitelist: [],
            enforceWhitelist: true,
            dropdown: { enabled: 1, maxItems: 20, closeOnSelect: false, searchKeys: ['value', 'searchBy'], mapValueTo: 'mappedValue' },
            tagTextProp: 'value',
            addTagOn: [],
            addTagOnBlur: false,
        });
        this._timer = null;
        this._seq = 0;
        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('change', this._onCoatingsChange.bind(this));

        if (this.selectedValue && this.selectedValue.id) {
            this._select(this.selectedValue.id, this.selectedValue.title);
        } else {
            this._renderSelected(null);
        }
    }

    disconnect() {
        if (this.tagify) this.tagify.destroy();
        if (this._timer) clearTimeout(this._timer);
    }

    _onInput(e) {
        const q = (e.detail.value || '').trim();
        if (this._timer) clearTimeout(this._timer);
        if (q !== '') {
            this.tagify.whitelist = [{ value: q, mappedValue: 'Идёт поиск…', searchBy: q, __loading: true }];
            this.tagify.dropdown.show.call(this.tagify, q);
        }
        this._timer = setTimeout(() => this._suggest(q), 250);
    }

    async _suggest(q) {
        q = q.trim();
        if (q === '') {
            this.tagify.whitelist = [];
            this.tagify.dropdown.refilter.call(this.tagify);
            return;
        }
        this._seq += 1;
        const seq = this._seq;
        let raw = [];
        try {
            const u = new URL(this.coatingSuggestUrlValue, window.location.origin);
            u.searchParams.set('q', q);
            const r = await fetch(u, { credentials: 'same-origin' });
            if (r.ok) {
                const j = await r.json();
                const d = j.data ?? j;
                raw = Array.isArray(d.items) ? d.items : (Array.isArray(d) ? d : []);
            }
        } catch (err) {
            // сеть недоступна
        }
        if (seq !== this._seq) return;
        this.tagify.whitelist = raw.map(t => ({ value: t.title, id: t.id, searchBy: q }));
        this.tagify.dropdown.show.call(this.tagify, q);
    }

    _onCoatingsChange() {
        const ids = (this.tagify.value || []).map(t => t.id).filter(Boolean);
        this._fetchSystems(ids);
    }

    async _fetchSystems(ids) {
        if (ids.length === 0) {
            this.resultsTarget.innerHTML = '';
            return;
        }
        const u = new URL(this.systemsUrlValue, window.location.origin);
        ids.forEach(id => u.searchParams.append('coatingIds[]', id));
        let items = [];
        try {
            const r = await fetch(u, { credentials: 'same-origin' });
            if (r.ok) {
                const j = await r.json();
                items = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
            }
        } catch (err) {
            // сеть недоступна
        }
        this._renderResults(items);
    }

    _renderResults(items) {
        if (items.length === 0) {
            this.resultsTarget.innerHTML = '<div class="text-body-secondary small mt-2">Систем с этими покрытиями не найдено.</div>';
            return;
        }
        this.resultsTarget.innerHTML = '';
        items.forEach(s => {
            const meta = [s.substrate, s.environment, s.prep].filter(Boolean).join(' · ');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-100 text-start p-2 mb-1 rounded-3 bg-body border-0 sys-opt';
            btn.innerHTML = `<div class="fw-semibold">${this._esc(s.title)}</div>`
                + `<div class="small text-body-secondary">${this._esc(meta)} · ${Number(s.dft)} мкм</div>`;
            btn.addEventListener('click', () => this._select(s.id, s.title));
            this.resultsTarget.appendChild(btn);
        });
    }

    _select(id, title) {
        this._renderSelected({ id, title });
        this._renderHidden(id);
        this.resultsTarget.innerHTML = '';
    }

    clear() {
        this._renderSelected(null);
        this._renderHidden('');
    }

    _renderSelected(sel) {
        if (sel && sel.id) {
            this.selectedTarget.innerHTML = `<span class="badge text-bg-primary">${this._esc(sel.title)}</span> `
                + '<button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-action="system-by-coating#clear">сменить</button>';
        } else {
            this.selectedTarget.innerHTML = '<span class="text-body-secondary small">Система не выбрана — введите покрытия ниже.</span>';
        }
    }

    _renderHidden(id) {
        this.element.querySelectorAll('input.system-by-coating-hidden').forEach(el => el.remove());
        if (id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = this.hiddenNameValue;
            input.value = id;
            input.className = 'system-by-coating-hidden';
            this.element.appendChild(input);
        }
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
}
