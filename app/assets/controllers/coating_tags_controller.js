import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Tagify-инпут для general-тегов покрытия.
 * Использует два AJAX-эндпоинта:
 *   - GET suggest для autocomplete по существующим тегам.
 *   - POST create для явного создания нового general-тега.
 *
 * При сабмите формы генерирует скрытые input'ы `tags[N][id]=...`.
 * Title в форму не уходит — теги к этому моменту уже существуют в БД.
 *
 * Все JSON-ответы приходят в envelope {data: ...}. Контроллер читает
 * result.data (с graceful fallback на result напрямую, если envelope
 * не активирован для конкретного endpoint'а).
 */
export default class extends Controller {
    static values = {
        existing: { type: Array, default: [] },
        suggestUrl: { type: String, default: '/cabinet/coating/coating-tag/suggest' },
        createUrl: { type: String, default: '/cabinet/coating/coating-tag' },
    };

    connect() {
        this.tagify = new Tagify(this.element, {
            enforceWhitelist: true,
            whitelist: [],
            dropdown: {
                enabled: 1,
                maxItems: 10,
                closeOnSelect: true,
                searchKeys: ['value'],
            },
            templates: {
                dropdownItemNoMatch: (data) => this._noMatchTemplate(data),
            },
            tagTextProp: 'value',
        });

        this._debounceTimer = null;

        // Заполняем initial-значения, если есть.
        if (this.existingValue.length) {
            this.tagify.addTags(this.existingValue.map(t => ({ value: t.title, id: t.id })));
        }

        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('change', this._renderHiddenInputs.bind(this));
        this.tagify.DOM.scope.addEventListener('click', this._onDropdownClick.bind(this));

        this._renderHiddenInputs();
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
        this._debounceTimer = setTimeout(() => this._fetchSuggest(query), 250);
    }

    async _fetchSuggest(query) {
        if (!query) {
            this.tagify.whitelist = [];
            this.tagify.dropdown.refilter.call(this.tagify, query);
            return;
        }
        try {
            const url = new URL(this.suggestUrlValue, window.location.origin);
            url.searchParams.set('q', query);
            url.searchParams.set('type', 'general');
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (!resp.ok) return;
            const jsonResponse = await resp.json();
            // Envelope: {data: [...]}; fallback на прямой массив.
            const raw = jsonResponse.data ?? jsonResponse;
            const items = Array.isArray(raw) ? raw : [];
            this.tagify.whitelist = items.map(t => ({ value: t.title, id: t.id }));
            this.tagify.dropdown.refilter.call(this.tagify, query);
        } catch (e) {
            // Сетевые ошибки — молча, тег создать всё ещё можно через клик «Создать».
        }
    }

    _noMatchTemplate(data) {
        const title = (data.value || '').replace(/[<>&"']/g, '');
        return `
            <div class="tagify__dropdown__item tagify-create-item" data-create-title="${title}">
                + Создать «${title}»
            </div>
        `;
    }

    async _onDropdownClick(event) {
        const createItem = event.target.closest('.tagify-create-item');
        if (!createItem) return;
        event.preventDefault();
        const title = createItem.dataset.createTitle;
        if (!title) return;

        try {
            const resp = await fetch(this.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title }),
            });
            if (resp.status === 201) {
                const envelope = await resp.json();
                // Envelope: {data: {id, title}}; fallback на прямой объект.
                const created = envelope.data ?? envelope;
                this.tagify.addTags([{ value: created.title, id: created.id }]);
                this.tagify.dropdown.hide.call(this.tagify);
            } else if (resp.status === 422) {
                const err = await resp.json();
                alert(err.message || err.error || 'Ошибка создания тега.');
            } else {
                alert('Не удалось создать тег.');
            }
        } catch (e) {
            alert('Сетевая ошибка при создании тега.');
        }
    }

    _renderHiddenInputs() {
        // Удаляем старые скрытые inputs.
        const formGroup = this.element.closest('[data-coating-tags-group]') || this.element.parentElement;
        formGroup.querySelectorAll('input.coating-tag-hidden').forEach(el => el.remove());

        // Создаём по одному hidden на каждый chip.
        const values = this.tagify.value || [];
        values.forEach((v, i) => {
            if (!v.id) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `tags[${i}][id]`;
            input.value = v.id;
            input.className = 'coating-tag-hidden';
            formGroup.appendChild(input);
        });
    }
}
