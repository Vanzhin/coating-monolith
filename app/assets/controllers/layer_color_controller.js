import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Одиночный typeahead выбора цвета для слоя системы (Tagify mode:'select').
 * Источник подсказок зависит от покрытия слоя (грузится с /coating/{id}/colors):
 *  - не колеруемое → только возможные цвета этого покрытия (локальная фильтрация);
 *  - колеруемое   → глобальный справочник (/color/suggest) + «+ Создать» (модалка Плана 1).
 * Владеет своим скрытым инпутом `layers[i][colorId]` (имя задаётся value `field`,
 * родительский layers-edit меняет его при renumber).
 */
export default class extends Controller {
    static values = {
        coatingId: { type: String, default: '' },
        field: { type: String, default: '' },
        colorsUrl: { type: String, default: '' }, // шаблон с __ID__
        suggestUrl: { type: String, default: '/cabinet/coating/color/suggest' },
        selected: { type: Object, default: {} },
    };

    connect() {
        this._debounceTimer = null;
        this._fetchSeq = 0;
        this._isTintable = false;
        this._coatingColors = [];
        this._pendingSelectedId = null;
        this._loadedCoatingId = null;

        this.tagify = new Tagify(this.element, {
            whitelist: [],
            // Чип не редактируется по клику (иначе повторный выбор того же цвета убивал бы тег).
            // Смена цвета — только через крестик (удалить) + выбрать заново.
            editTags: false,
            dropdown: {
                enabled: 0,
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

        if (this.selectedValue && this.selectedValue.id) {
            if (this.selectedValue.name) {
                this.tagify.addTags([this._toTag(this.selectedValue)]);
            } else {
                // Пришёл только id (перерендер формы после ошибки) — дотянем цвет из /colors.
                this._pendingSelectedId = this.selectedValue.id;
            }
        }

        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('focus', this._onFocus.bind(this));
        this.tagify.on('add', this._onAdd.bind(this));
        this.tagify.on('remove', this._onRemove.bind(this));

        this._renderHidden();
        if (this.coatingIdValue) {
            this._loadCoatingColors();
        }
    }

    disconnect() {
        if (this.tagify) this.tagify.destroy();
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
    }

    coatingIdValueChanged() {
        // Stimulus зовёт этот колбэк и для начального значения при подключении.
        // Пропускаем, если контроллер ещё не поднялся (tagify нет) или покрытие не
        // изменилось относительно уже загруженного (иначе начальный вызов стёр бы
        // выбранный цвет через removeAllTags). Реальная смена покрытия у строки —
        // только в форме добавления.
        if (!this.tagify || this.coatingIdValue === this._loadedCoatingId) {
            return;
        }
        this.tagify.removeAllTags();
        this._renderHidden();
        if (this.coatingIdValue) {
            this._loadCoatingColors();
        }
    }

    fieldValueChanged() {
        this._renderHidden();
    }

    /** Публичный вход из модалки создания цвета (для колеруемого покрытия). */
    addColor(color) {
        if (!color || !color.id || !this.tagify) return;
        this.tagify.removeAllTags();
        this.tagify.addTags([this._toTag(color)]);
        this._renderHidden();
    }

    async _loadCoatingColors() {
        if (!this.colorsUrlValue) return;
        this._loadedCoatingId = this.coatingIdValue;
        const url = this.colorsUrlValue.replace('__ID__', encodeURIComponent(this.coatingIdValue));
        try {
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (!resp.ok) return;
            const json = await resp.json();
            const data = json.data ?? json;
            this._isTintable = Boolean(data.isTintable);
            this._coatingColors = Array.isArray(data.colors) ? data.colors : [];

            if (this._pendingSelectedId) {
                const found = this._coatingColors.find(c => c.id === this._pendingSelectedId);
                if (found) {
                    this.tagify.addTags([this._toTag(found)]);
                    this._renderHidden();
                }
                this._pendingSelectedId = null;
            }
        } catch (e) {
            // Сеть недоступна — оставляем пустой список.
        }
    }

    _onFocus() {
        // Не колеруемое: фокус на поле сразу открывает список цветов покрытия — выбор как из селекта.
        // Колеруемое: списка нет — пользователь печатает (_onInput → глобальный поиск + «Создать»).
        if (this._isTintable) return;
        const items = this._coatingColors.map(c => ({ ...this._toTag(c), searchBy: '' }));
        if (items.length === 0) {
            items.push({ value: 'нет', mappedValue: 'У покрытия нет цветов', searchBy: '', __empty: true });
        }
        this.tagify.whitelist = items;
        this.tagify.dropdown.show.call(this.tagify, '');
    }

    _onInput(e) {
        const query = (e.detail.value || '').trim();
        if (this._debounceTimer) clearTimeout(this._debounceTimer);

        if (!this._isTintable) {
            // Не колеруемое: фильтруем возможные цвета покрытия локально.
            const matches = this._coatingColors
                .filter(c => this._matches(c, query))
                .map(c => ({ ...this._toTag(c), searchBy: query }));
            if (matches.length === 0) {
                matches.push({
                    value: query || 'нет',
                    mappedValue: this._coatingColors.length
                        ? 'Ничего не найдено'
                        : 'У покрытия нет цветов',
                    searchBy: query,
                    __empty: true,
                });
            }
            this.tagify.whitelist = matches;
            this.tagify.dropdown.show.call(this.tagify, query);
            return;
        }

        // Колеруемое: глобальный справочник + «+ Создать».
        if (query !== '') {
            this.tagify.whitelist = [{ value: query, mappedValue: 'Идёт поиск…', searchBy: query, __loading: true }];
            this.tagify.dropdown.show.call(this.tagify, query);
        }
        this._debounceTimer = setTimeout(() => this._fetchGlobal(query), 250);
    }

    async _fetchGlobal(query) {
        const trimmed = query.trim();
        this._fetchSeq += 1;
        const mySeq = this._fetchSeq;

        let raw = [];
        if (trimmed !== '') {
            try {
                const url = new URL(this.suggestUrlValue, window.location.origin);
                url.searchParams.set('q', trimmed);
                const resp = await fetch(url, { credentials: 'same-origin' });
                if (resp.ok) {
                    const json = await resp.json();
                    const data = json.data ?? json;
                    raw = Array.isArray(data) ? data : [];
                }
            } catch (e) {
                // только «+ Создать»
            }
        }
        if (mySeq !== this._fetchSeq) return;

        const items = raw.map(c => ({ ...this._toTag(c), searchBy: query }));
        if (trimmed !== '' && !items.some(i => (i.value || '').toLocaleLowerCase() === trimmed.toLocaleLowerCase())) {
            items.push({ value: query, mappedValue: `+ Создать «${trimmed}»`, searchBy: query, __create: true });
        }
        this.tagify.whitelist = items;
        this.tagify.dropdown.show.call(this.tagify, query);
    }

    _onAdd(e) {
        const tagData = e.detail.data;
        const tagElm = e.detail.tag;

        if (tagData.__loading || tagData.__empty) {
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
            // Одиночный выбор: оставляем только что выбранный тег, прежний убираем.
            const others = this.tagify.getTagElms().filter(el => el !== tagElm);
            if (others.length) {
                this.tagify.removeTags(others);
            }
            this._renderHidden();
            return;
        }
        this.tagify.removeTags(tagElm);
    }

    _onRemove() {
        this._renderHidden();
    }

    _openCreateModal(name) {
        const wrapper = document.querySelector('[data-controller~="color-create-modal"]');
        const app = window.Stimulus;
        if (wrapper && app) {
            const ctrl = app.getControllerForElementAndIdentifier(wrapper, 'color-create-modal');
            if (ctrl) ctrl.open(name, this);
        }
    }

    _matches(color, query) {
        if (query === '') return true;
        const q = query.toLocaleLowerCase();
        return (color.name || '').toLocaleLowerCase().includes(q)
            || (color.ral || '').toLocaleLowerCase().includes(q);
    }

    _toTag(color) {
        return { value: color.name, id: color.id, ral: color.ral || '', hex: color.hex || '', label: color.label || color.name };
    }

    _selectedId() {
        const v = this.tagify && this.tagify.value.length ? this.tagify.value[0] : null;
        return v && v.id ? v.id : '';
    }

    _renderHidden() {
        this._syncFilled();
        const group = this.element.closest('[data-layer-color-group]') || this.element.parentElement;
        group.querySelectorAll('input.layer-color-hidden').forEach(el => el.remove());
        if (!this.fieldValue) return;

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = this.fieldValue;
        input.value = this._selectedId();
        input.className = 'layer-color-hidden';
        group.appendChild(input);
    }

    _syncFilled() {
        const scope = this.tagify && this.tagify.DOM ? this.tagify.DOM.scope : null;
        if (scope) {
            scope.classList.toggle('layer-color--filled', this.tagify.value.length > 0);
        }
    }

    _escape(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    _tagTemplate(tagData) {
        const cn = this.tagify.settings.classNames;
        const swatch = tagData.hex ? `<span class="color-swatch" style="background:${this._escape(tagData.hex)}"></span>` : '';
        const label = tagData.label || tagData.value;

        return `<tag title="${this._escape(label)}" contenteditable="false" spellcheck="false"
                     tabindex="-1" class="${cn.tag} ${tagData.class || ''}" ${this.tagify.getAttributes(tagData)}>
            <x title="" class="${cn.tagX}" role="button" aria-label="remove tag"></x>
            <div class="d-inline-flex align-items-center">
                ${swatch}<span class="${cn.tagText}">${this._escape(label)}</span>
            </div>
        </tag>`;
    }

    _dropdownItemTemplate(item) {
        const cn = this.tagify.settings.classNames;
        if (item.__create || item.__loading || item.__empty) {
            return `<div ${this.tagify.getAttributes(item)} class="${cn.dropdownItem} small text-muted"
                         style="white-space:normal" tabindex="0" role="option">
                ${this._escape(item.mappedValue || item.value)}
            </div>`;
        }
        const swatch = item.hex ? `<span class="color-swatch" style="background:${this._escape(item.hex)}"></span>` : '';
        const ral = item.ral ? ` <span class="text-secondary small ms-1">${this._escape(item.ral)}</span>` : '';

        return `<div ${this.tagify.getAttributes(item)} class="${cn.dropdownItem} d-flex align-items-center"
                     tabindex="0" role="option">
            ${swatch}<span>${this._escape(item.value)}</span>${ral}
        </div>`;
    }
}
