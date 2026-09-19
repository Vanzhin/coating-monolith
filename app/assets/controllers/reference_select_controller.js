import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Одиночный выбор ссылки (проект/заказчик/подрядчик) «как теги»: поиск по suggest + синтетик-опция
 * «+ Создать «X»» → POST на createUrl, на 201 чип получает id. Пишет ОДИН hidden-input (hiddenName)
 * с id выбранной сущности. Для проекта: counterpartyName указывает имя hidden-инпута заказчика,
 * чей id уходит в тело создания (проекту в домене обязателен контрагент).
 */
export default class extends Controller {
    static values = {
        suggestUrl: { type: String, default: '' },
        createUrl: { type: String, default: '' },
        hiddenName: { type: String, default: '' },
        allowCreate: { type: Boolean, default: true },
        counterpartyName: { type: String, default: '' },
    };

    connect() {
        this.tagify = new Tagify(this.element, {
            whitelist: [],
            mode: 'select',
            maxTags: 1,
            dropdown: { enabled: 1, maxItems: 20, closeOnSelect: true, searchKeys: ['value', 'searchBy'], mapValueTo: 'mappedValue' },
            tagTextProp: 'value',
            addTagOn: [],
            addTagOnBlur: false,
        });
        this._debounceTimer = null;
        this._fetchSeq = 0;

        this.hint = document.createElement('div');
        this.hint.className = 'form-text text-warning d-none';
        this.hint.textContent = 'Выберите из списка или нажмите «+ Создать» — иначе не сохранится.';
        (this.element.closest('[data-reference-select-group]') || this.element.parentElement).appendChild(this.hint);

        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('add', this._onAdd.bind(this));
        this.tagify.on('change', this._renderHidden.bind(this));
        this.tagify.on('add', this._clearWarn.bind(this));
        this.tagify.on('input', this._clearWarn.bind(this));
        this.tagify.on('blur', this._onBlur.bind(this));
        this._renderHidden();
    }

    _onBlur() {
        const leftover = (this.tagify.state.inputText || '').trim();
        if (leftover !== '') {
            this.tagify.DOM.scope.classList.add('reference-select-warn');
            this.hint.classList.remove('d-none');
        }
    }

    _clearWarn() {
        this.tagify.DOM.scope.classList.remove('reference-select-warn');
        this.hint.classList.add('d-none');
    }

    disconnect() {
        if (this.tagify) {
            this.tagify.destroy();
        }
        if (this._debounceTimer) {
            clearTimeout(this._debounceTimer);
        }
    }

    _onInput(e) {
        const query = e.detail.value || '';
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
        if (query.trim() !== '') {
            this.tagify.whitelist = [{ value: query, mappedValue: 'Идёт поиск…', searchBy: query, __loading: true }];
            this.tagify.dropdown.show.call(this.tagify, query);
        }
        this._debounceTimer = setTimeout(() => this._fetchSuggest(query), 250);
    }

    async _fetchSuggest(query) {
        const trimmed = query.trim();
        if (trimmed === '') {
            this.tagify.whitelist = [];
            this.tagify.dropdown.refilter.call(this.tagify);
            return;
        }
        this._fetchSeq += 1;
        const mySeq = this._fetchSeq;

        let raw = [];
        try {
            const url = new URL(this.suggestUrlValue, window.location.origin);
            url.searchParams.set('q', trimmed);
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (resp.ok) {
                const json = await resp.json();
                const data = json.data ?? json;
                raw = Array.isArray(data) ? data : (Array.isArray(data.items) ? data.items : []);
            }
        } catch (err) {
            // сеть недоступна → только «+ Создать»
        }
        if (mySeq !== this._fetchSeq) return;

        const items = raw.map(t => ({ value: t.title, id: t.id, searchBy: query }));
        if (this.allowCreateValue && !items.some(t => (t.value || '').toLocaleLowerCase() === trimmed.toLocaleLowerCase())) {
            items.push({ value: query, mappedValue: `+ Создать «${trimmed}»`, searchBy: query, __create: true });
        }
        if (items.length === 0) {
            items.push({ value: query, mappedValue: 'Ничего не найдено', searchBy: query, __empty: true });
        }
        this.tagify.whitelist = items;
        this.tagify.dropdown.show.call(this.tagify, query);
    }

    async _onAdd(e) {
        const tagData = e.detail.data;
        const tagElm = e.detail.tag;

        if (tagData.__loading || tagData.__empty) {
            this.tagify.removeTags(tagElm);
            return;
        }
        if (tagData.id) {
            return; // существующая — id уйдёт в hidden через change
        }
        if (!this.allowCreateValue) {
            this.tagify.removeTags(tagElm);
            return;
        }

        const title = (tagData.value || '').trim();
        if (title === '') {
            this.tagify.removeTags(tagElm);
            return;
        }

        const body = { title };
        if (this.counterpartyNameValue !== '') {
            const cp = document.querySelector(`input[name="${this.counterpartyNameValue}"]`);
            if (!cp || cp.value === '') {
                this.tagify.removeTags(tagElm);
                alert('Сначала выберите заказчика — новый проект создаётся под ним.');
                return;
            }
            body.counterpartyId = cp.value;
        }

        try {
            const resp = await fetch(this.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (resp.status === 201) {
                const envelope = await resp.json();
                const created = envelope.data ?? envelope;
                this.tagify.replaceTag(tagElm, { value: created.title, id: created.id });
                this._renderHidden();
                return;
            }
            const err = resp.headers.get('Content-Type')?.includes('json') ? await resp.json() : {};
            this.tagify.removeTags(tagElm);
            alert(err.message || 'Не удалось создать запись.');
        } catch (err) {
            this.tagify.removeTags(tagElm);
            alert('Сетевая ошибка при создании.');
        }
    }

    _renderHidden() {
        const group = this.element.closest('[data-reference-select-group]') || this.element.parentElement;
        group.querySelectorAll('input.reference-select-hidden').forEach(el => el.remove());

        const chosen = (this.tagify.value || [])[0];
        if (chosen && chosen.id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = this.hiddenNameValue;
            input.value = chosen.id;
            input.className = 'reference-select-hidden';
            group.appendChild(input);
        }
    }
}
