import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Tagify-инпут для general-тегов покрытия.
 *  - GET suggest — autocomplete по существующим тегам (FTS + fuzzy на бэке).
 *  - POST create — создание нового general-тега.
 *
 * Поведение dropdown:
 *  - сразу после ввода — synthetic loading-item (mappedValue «Идёт поиск…»).
 *  - после ответа — найденные теги + всегда synthetic create-item «+ Создать «X»»,
 *    если точного совпадения нет.
 *  - выбор любого item'а уходит в Tagify.add → _onAdd. Items с id —
 *    существующие теги (no-op), items с __create — POST'ятся, на 201 чип
 *    получает реальный id через replaceTag.
 *
 * Ключевая деталь: на каждом whitelist-item ставим `searchBy: query` —
 * иначе Tagify сам фильтрует whitelist локально по подстроке и режет
 * fuzzy-результаты сервера (типа «супер» по запросу «сап»).
 */
export default class extends Controller {
    static values = {
        existing: { type: Array, default: [] },
        suggestUrl: { type: String, default: '/cabinet/coating/coating-tag/suggest' },
        createUrl: { type: String, default: '/cabinet/coating/coating-tag' },
        // Shape hidden-input'ов. Дефолт — вложенный `tags[][id]` для формы покрытия
        // (парсится в POST-теле как список объектов). Для страницы поиска нужен плоский
        // `tagIds[]` — переопределяется через data-coating-tags-hidden-input-name-value.
        hiddenInputName: { type: String, default: 'tags[][id]' },
        // На странице поиска — только выбор существующих. Создание тега — только
        // при создании/редактировании покрытия.
        allowCreate: { type: Boolean, default: true },
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
            // Создавать тег ТОЛЬКО при клике на «+ Создать «X»» в dropdown'е.
            // Дефолтные триггеры blur/tab/enter не должны автоматически
            // создавать тег из набранного, незавершённого текста.
            addTagOn: [],
            addTagOnBlur: false,
        });

        this._debounceTimer = null;
        this._fetchSeq = 0;

        if (this.existingValue.length) {
            this.tagify.addTags(this.existingValue.map(t => ({ value: t.title, id: t.id })));
        }

        this.tagify.on('input', this._onInput.bind(this));
        this.tagify.on('add', this._onAdd.bind(this));
        this.tagify.on('change', this._renderHiddenInputs.bind(this));

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
        const trimmed = query.trim();
        if (trimmed !== '') {
            // Tagify сам зовёт dropdown.show(value) ПЕРЕД триггером 'input'
            // event'а с пустым whitelist'ом → dropdown сразу скрывается.
            // Поэтому ставим whitelist+show вручную после своего handler'a.
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
                const jsonResponse = await resp.json();
                const data = jsonResponse.data ?? jsonResponse;
                raw = Array.isArray(data) ? data : [];
            }
        } catch (e) {
            // Сетевая ошибка → только «+ Создать».
        }

        if (mySeq !== this._fetchSeq) return;

        // searchBy = query гарантирует, что Tagify-фильтр не выбросит даже
        // те fuzzy-результаты, в которых query не substring title.
        const items = raw.map(t => ({
            value: t.title,
            id: t.id,
            searchBy: query,
        }));

        if (this.allowCreateValue) {
            const exactMatch = items.some(
                t => (t.value || '').toLocaleLowerCase() === trimmed.toLocaleLowerCase()
            );
            if (!exactMatch) {
                items.push({
                    value: query,
                    mappedValue: `+ Создать «${trimmed}»`,
                    searchBy: query,
                    __create: true,
                });
            }
        }

        // Ничего не нашли и создание отключено — показываем явный empty-state,
        // иначе dropdown залипает на предыдущем «Идёт поиск…».
        if (items.length === 0) {
            items.push({
                value: query,
                mappedValue: 'Ничего не найдено',
                searchBy: query,
                __empty: true,
            });
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
            return;
        }

        // Тег без id и allowCreate=false — вход только через suggest (существующие).
        // Если пользователь как-то попал сюда (например, ручной ввод + Enter, что мы
        // и так отключаем addTagOn=[]) — молча удаляем.
        if (!this.allowCreateValue) {
            this.tagify.removeTags(tagElm);
            return;
        }

        const title = (tagData.value || '').trim();
        if (title === '') {
            this.tagify.removeTags(tagElm);
            return;
        }

        try {
            const resp = await fetch(this.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title }),
            });
            if (resp.status === 201) {
                const envelope = await resp.json();
                const created = envelope.data ?? envelope;
                this.tagify.replaceTag(tagElm, { value: created.title, id: created.id });
                this._renderHiddenInputs();
                return;
            }
            if (resp.status === 422) {
                const err = await resp.json();
                this.tagify.removeTags(tagElm);
                alert(err.message || err.error || 'Ошибка создания тега.');
                return;
            }
            this.tagify.removeTags(tagElm);
            alert('Не удалось создать тег.');
        } catch (err) {
            this.tagify.removeTags(tagElm);
            alert('Сетевая ошибка при создании тега.');
        }
    }

    _renderHiddenInputs() {
        const formGroup = this.element.closest('[data-coating-tags-group]') || this.element.parentElement;
        formGroup.querySelectorAll('input.coating-tag-hidden').forEach(el => el.remove());

        const values = this.tagify.value || [];
        values.forEach(v => {
            if (!v.id) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = this.hiddenInputNameValue;
            input.value = v.id;
            input.className = 'coating-tag-hidden';
            formGroup.appendChild(input);
        });
    }
}
