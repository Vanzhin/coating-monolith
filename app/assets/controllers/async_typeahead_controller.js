import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Превращает обычный <select> в typeahead-поле с асинхронным поиском через API.
 *
 * Использование:
 *   <div data-controller="async-typeahead"
 *        data-async-typeahead-endpoint-value="/api/surface-treatments"
 *        data-async-typeahead-placeholder-value="Поиск...">
 *     <select name="fieldName" data-async-typeahead-target="select" required>
 *       <option value="">Выберите...</option>
 *       <!-- опционально: preselected option при редактировании -->
 *     </select>
 *   </div>
 *
 * API endpoint должен принимать ?q=<query> и возвращать:
 *   { data: { items: [{id, title, ...}] } }  или  { items: [{id, title, ...}] }
 *
 * Поле title берётся из item.title ?? item.description.
 */
export default class extends Controller {
    static targets = ['select'];
    static values = {
        endpoint: String,
        placeholder: { type: String, default: 'Поиск...' },
        minLength: { type: Number, default: 2 },
    };

    connect() {
        const select = this.selectTarget;
        const name = select.name;
        const required = select.required;

        // Выбранный option при открытии формы (Update-режим)
        let preselected = null;
        Array.from(select.options).forEach(opt => {
            if (opt.value && opt.selected) {
                preselected = { value: opt.textContent.trim(), id: opt.value };
            }
        });

        // Скрываем select, снимаем required (форма не сабмитится с hidden select)
        select.style.display = 'none';
        select.required = false;
        select.name = '';

        // Создаём input для Tagify
        this._input = document.createElement('input');
        this._input.type = 'text';
        this._input.className = 'form-control';
        this._input.placeholder = this.placeholderValue;
        select.parentNode.insertBefore(this._input, select.nextSibling);

        // Скрытый input для передачи id в форму
        this._hidden = document.createElement('input');
        this._hidden.type = 'hidden';
        this._hidden.name = name;
        if (required) this._hidden.required = true;
        select.parentNode.insertBefore(this._hidden, select.nextSibling);

        this._tagify = new Tagify(this._input, {
            whitelist: [],
            enforceWhitelist: true,
            mode: 'select',
            dropdown: {
                enabled: 0,
                maxItems: 30,
                highlightFirst: true,
                closeOnSelect: true,
            },
            tagTextProp: 'value',
        });

        if (preselected) {
            this._tagify.addTags([preselected]);
            this._hidden.value = preselected.id;
        }

        this._tagify.on('input', e => this._onInput(e));

        this._tagify.on('add', e => {
            this._hidden.value = e.detail.data.id ?? '';
        });

        this._tagify.on('remove', () => {
            this._hidden.value = '';
        });
    }

    async _onInput(e) {
        const value = (e.detail.value ?? '').trim();
        if (value.length < this.minLengthValue) {
            return;
        }

        this._tagify.whitelist = null;
        this._tagify.loading(true).dropdown.hide.call(this._tagify);

        try {
            const url = `${this.endpointValue}?q=${encodeURIComponent(value)}`;
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                this._tagify.loading(false);
                return;
            }

            const data = await response.json();
            const raw = data.data?.items ?? data.items ?? [];

            const items = raw.map(item => ({
                value: item.title ?? item.description ?? '',
                id: item.id,
            }));

            this._tagify.whitelist = items;
            this._tagify.loading(false).dropdown.show.call(this._tagify, value);
        } catch {
            this._tagify.loading(false);
        }
    }

    /**
     * Программно выбирает элемент — используется при создании нового treatment через модалку.
     * @param {string} id
     * @param {string} title
     */
    selectItem(id, title) {
        if (!this._tagify) return;
        this._tagify.removeAllTags();
        this._tagify.addTags([{ value: title, id }]);
        this._hidden.value = id;
    }

    disconnect() {
        if (this._tagify) {
            this._tagify.destroy();
            this._tagify = null;
        }
        if (this._input && this._input.parentNode) {
            this._input.parentNode.removeChild(this._input);
            this._input = null;
        }
        if (this._hidden && this._hidden.parentNode) {
            this._hidden.parentNode.removeChild(this._hidden);
            this._hidden = null;
        }
        const select = this.selectTarget;
        select.style.display = '';
    }
}
