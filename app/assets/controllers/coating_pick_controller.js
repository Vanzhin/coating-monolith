import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Одиночный пикер покрытия для слоя (материал). Пишет в hidden ТОЛЬКО id покрытия — снимок {id,title}
 * бэк соберёт при сохранении. hidden несёт name (его переиндексирует контроллер строк), search —
 * только для отображения/поиска (без name). Работает внутри повторяемой строки слоя.
 */
export default class extends Controller {
    static targets = ['hidden', 'search'];
    static values = { suggestUrl: { type: String, default: '' } };

    connect() {
        const initialId = this.hiddenTarget.value;
        const initialTitle = this.searchTarget.value;
        this.searchTarget.value = '';

        this.tagify = new Tagify(this.searchTarget, {
            whitelist: [],
            enforceWhitelist: true,
            mode: 'select',
            maxTags: 1,
            dropdown: { enabled: 1, maxItems: 20, closeOnSelect: true, searchKeys: ['value', 'searchBy'], mapValueTo: 'mappedValue' },
            tagTextProp: 'value',
            addTagOn: [],
            addTagOnBlur: false,
        });
        this._timer = null;
        this._seq = 0;
        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('add', this._onAdd.bind(this));
        this.tagify.on('remove', this._onRemove.bind(this));

        if (initialId && initialTitle) {
            this.tagify.addTags([{ value: initialTitle, id: initialId }]);
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
            const u = new URL(this.suggestUrlValue, window.location.origin);
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

    _onAdd(e) {
        const data = e.detail.data;
        if (data.__loading) {
            this.tagify.removeTags(e.detail.tag);
            return;
        }
        if (data.id) {
            this.hiddenTarget.value = data.id;
            this.hiddenTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    _onRemove() {
        this.hiddenTarget.value = '';
        this.hiddenTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
