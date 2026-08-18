import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Tagify-инпут для возможных цветов покрытия. Зеркалит coating-tags, но:
 *  - подсказки — полные цвета {id, name, ral, hex}; чип и dropdown-item рисуют свотч;
 *  - «+ Создать» не POST'ит напрямую, а открывает модалку создания цвета
 *    (color-create-modal), которая по успеху зовёт addColor();
 *  - скрытые инпуты несут ПОЛНЫЕ данные: colors[i][id|name|ral|hex] — чтобы
 *    CoatingDTO собрался из полных ColorDTO. На сохранении бэк резолвит цвет по id.
 */
export default class extends Controller {
    static values = {
        existing: { type: Array, default: [] },
        suggestUrl: { type: String, default: '/cabinet/coating/color/suggest' },
    };

    connect() {
        this.tagify = new Tagify(this.element, {
            whitelist: [],
            dropdown: {
                enabled: 1,
                maxItems: 20,
                closeOnSelect: true,
                searchKeys: ['value', 'searchBy'],
                mapValueTo: 'mappedValue',
                includeSelectedTags: true,
            },
            tagTextProp: 'value',
            addTagOn: [],
            addTagOnBlur: false,
            templates: {
                tag: this._tagTemplate.bind(this),
                dropdownItem: this._dropdownItemTemplate.bind(this),
            },
        });

        this._debounceTimer = null;
        this._fetchSeq = 0;

        if (this.existingValue.length) {
            this.tagify.addTags(this.existingValue.map(c => this._toTag(c)));
        }

        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('add', this._onAdd.bind(this));
        this.tagify.on('change', this._renderHiddenInputs.bind(this));

        this._renderHiddenInputs();
    }

    disconnect() {
        if (this.tagify) this.tagify.destroy();
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
    }

    /** Публичный вход из модалки создания цвета. */
    addColor(color) {
        if (!color || !color.id) return;
        this.tagify.addTags([this._toTag(color)]);
        this._renderHiddenInputs();
    }

    _toTag(color) {
        return {
            value: color.name,
            id: color.id,
            ral: color.ral || '',
            hex: color.hex || '',
        };
    }

    _onInput(e) {
        const query = e.detail.value || '';
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
        if (query.trim() !== '') {
            this.tagify.whitelist = [{
                value: query,
                mappedValue: 'Идёт поиск…',
                searchBy: query,
                __loading: true,
            }];
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
                // Ответы обёрнуты глобальным ResponseListener в {data: …}.
                const json = await resp.json();
                const data = json.data ?? json;
                raw = Array.isArray(data) ? data : [];
            }
        } catch (e) {
            // Сеть недоступна → только «+ Создать».
        }

        if (mySeq !== this._fetchSeq) return;

        const items = raw.map(c => ({
            value: c.name,
            id: c.id,
            ral: c.ral || '',
            hex: c.hex || '',
            searchBy: query,
        }));

        const exactMatch = items.some(
            c => (c.value || '').toLocaleLowerCase() === trimmed.toLocaleLowerCase(),
        );
        if (!exactMatch) {
            items.push({
                value: query,
                mappedValue: `+ Создать «${trimmed}»`,
                searchBy: query,
                __create: true,
            });
        }

        this.tagify.whitelist = items;
        this.tagify.dropdown.show.call(this.tagify, query);
    }

    _onAdd(e) {
        const tagData = e.detail.data;
        const tagElm = e.detail.tag;

        if (tagData.__loading) {
            this.tagify.removeTags(tagElm);
            return;
        }

        if (tagData.__create) {
            const name = (tagData.value || '').trim();
            this.tagify.removeTags(tagElm);
            this._openCreateModal(name);
            return;
        }

        if (tagData.id) {
            return;
        }

        // Ручной ввод без выбора из dropdown — не создаём молча.
        this.tagify.removeTags(tagElm);
    }

    _openCreateModal(name) {
        const wrapper = document.querySelector('[data-controller~="color-create-modal"]');
        const app = window.Stimulus;
        if (wrapper && app) {
            const ctrl = app.getControllerForElementAndIdentifier(wrapper, 'color-create-modal');
            if (ctrl) ctrl.open(name);
        }
    }

    _renderHiddenInputs() {
        const group = this.element.closest('[data-coating-colors-group]') || this.element.parentElement;
        group.querySelectorAll('input.coating-color-hidden').forEach(el => el.remove());

        (this.tagify.value || []).forEach((c, i) => {
            if (!c.id) return;
            this._appendHidden(group, `colors[${i}][id]`, c.id);
            this._appendHidden(group, `colors[${i}][name]`, c.value || '');
            this._appendHidden(group, `colors[${i}][ral]`, c.ral || '');
            this._appendHidden(group, `colors[${i}][hex]`, c.hex || '');
        });
    }

    _appendHidden(group, name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        input.className = 'coating-color-hidden';
        group.appendChild(input);
    }

    _escape(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        // innerHTML не экранирует кавычки — значения идут в атрибуты (title, data-*, style).
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    _tagTemplate(tagData) {
        const cn = this.tagify.settings.classNames;
        const swatch = tagData.hex
            ? `<span class="color-swatch" style="background:${this._escape(tagData.hex)}"></span>`
            : '';

        return `<tag title="${this._escape(tagData.value)}" contenteditable="false" spellcheck="false"
                     tabindex="-1" class="${cn.tag} ${tagData.class || ''}" ${this.tagify.getAttributes(tagData)}>
            <x title="" class="${cn.tagX}" role="button" aria-label="remove tag"></x>
            <div class="d-inline-flex align-items-center">
                ${swatch}<span class="${cn.tagText}">${this._escape(tagData.value)}</span>
            </div>
        </tag>`;
    }

    _dropdownItemTemplate(item) {
        const cn = this.tagify.settings.classNames;

        if (item.__create || item.__loading) {
            return `<div ${this.tagify.getAttributes(item)} class="${cn.dropdownItem}" tabindex="0" role="option">
                ${this._escape(item.mappedValue || item.value)}
            </div>`;
        }

        const swatch = item.hex
            ? `<span class="color-swatch" style="background:${this._escape(item.hex)}"></span>`
            : '';
        const ral = item.ral ? ` <span class="text-secondary small ms-1">${this._escape(item.ral)}</span>` : '';

        return `<div ${this.tagify.getAttributes(item)} class="${cn.dropdownItem} d-flex align-items-center"
                     tabindex="0" role="option">
            ${swatch}<span>${this._escape(item.value)}</span>${ral}
        </div>`;
    }
}
