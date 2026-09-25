import { Controller } from '@hotwired/stimulus';

/**
 * Общий компонент «поиск-выбор» (typeahead) для всего приложения. Поле — обычный редактируемый текст:
 * значение видно, клик его не трогает; правка (дописал/удалил) с minLength-го символа запускает поиск
 * по эндпоинту (debounce). Выпадайка — штатный Bootstrap .dropdown-menu (в теме приложения): строки
 * «основное поле + вспомогательные» + опция «+ Создать». Скролл догружает следующую страницу, если
 * эндпоинт отдаёт hasMore (иначе — одна страница). Пишет один hidden-input (hiddenName) с id выбранного
 * и title-компаньон (<name>Title) для восстановления при ошибке валидации.
 *
 * ВАЖНО: НЕ переносим свой элемент в новую обёртку — Stimulus на перемещение узла передёргивает
 * connect/disconnect по кругу (страница виснет). Hidden-поля вставляем рядом с инпутом, а выпадайку
 * вешаем на document.body с position:fixed (её не режут overflow-контейнеры формы).
 *
 * Настройка через data-typeahead-*-value: endpoint, hidden-name, main-field, aux-fields [{field,label}],
 * create-url, create-modal (идентификатор Stimulus-контроллера модалки), allow-create, counterparty-name,
 * existing {id,title}, page-size, min-length.
 */
export default class extends Controller {
    static values = {
        endpoint: { type: String, default: '' },
        hiddenName: { type: String, default: '' },
        mainField: { type: String, default: 'title' },
        auxFields: { type: Array, default: [] },
        createUrl: { type: String, default: '' },
        createModal: { type: String, default: '' },
        allowCreate: { type: Boolean, default: true },
        counterpartyName: { type: String, default: '' },
        existing: { type: Object, default: {} },
        pageSize: { type: Number, default: 10 },
        minLength: { type: Number, default: 3 },
    };

    connect() {
        this.element.classList.add('form-control');
        this.element.setAttribute('type', 'text');
        this.element.setAttribute('autocomplete', 'off');

        this._debounce = null;
        this._seq = 0;
        this._items = [];
        this._activeIndex = -1;
        this._query = '';
        this._page = 1;
        this._hasMore = false;
        this._loadingMore = false;

        // hidden с id — рядом с инпутом (тот же родитель → уходит в форму). Элемент НЕ переносим.
        this._hidden = document.createElement('input');
        this._hidden.type = 'hidden';
        this._hidden.name = this.hiddenNameValue;
        this.element.insertAdjacentElement('afterend', this._hidden);

        // title-компаньон (<name>Title): бэк восстанавливает чип из формы при ошибке валидации.
        this._hiddenTitle = document.createElement('input');
        this._hiddenTitle.type = 'hidden';
        this._hiddenTitle.name = /Id$/.test(this.hiddenNameValue)
            ? this.hiddenNameValue.replace(/Id$/, 'Title')
            : this.hiddenNameValue + 'Title';
        this._hidden.insertAdjacentElement('afterend', this._hiddenTitle);

        // подсказка о незавершённом вводе — под инпутом
        this._hint = document.createElement('div');
        this._hint.className = 'form-text text-warning d-none';
        this._hint.textContent = 'Выберите из списка или нажмите «+ Создать» — иначе не сохранится.';
        this._hiddenTitle.insertAdjacentElement('afterend', this._hint);

        // выпадайка на body (fixed) — не режется overflow формы
        this._menu = document.createElement('div');
        this._menu.className = 'dropdown-menu typeahead-menu shadow';
        document.body.appendChild(this._menu);

        if (this.existingValue && this.existingValue.id) {
            this.element.value = this.existingValue.title || '';
            this._hidden.value = this.existingValue.id;
            this._hiddenTitle.value = this.existingValue.title || '';
        }

        this._onInput = this._onInput.bind(this);
        this._onKeydown = this._onKeydown.bind(this);
        this._onBlur = this._onBlur.bind(this);
        this._onScroll = this._onScroll.bind(this);
        this._onDocPointer = this._onDocPointer.bind(this);
        this._reposition = this._reposition.bind(this);
        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('keydown', this._onKeydown);
        this.element.addEventListener('blur', this._onBlur);
        this._menu.addEventListener('scroll', this._onScroll);
        document.addEventListener('pointerdown', this._onDocPointer);
    }

    disconnect() {
        if (this._debounce) clearTimeout(this._debounce);
        document.removeEventListener('pointerdown', this._onDocPointer);
        this._detachReposition();
        if (this._menu) this._menu.remove();
    }

    _onInput() {
        this._hidden.value = ''; // правка инвалидирует выбор, пока не выбрана новая строка
        this._hiddenTitle.value = '';
        this._clearHint();

        const q = this.element.value.trim();
        if (this._debounce) clearTimeout(this._debounce);
        if (q.length < this.minLengthValue) {
            this._hide();
            return;
        }
        this._loadingState();
        this._debounce = setTimeout(() => this._fetch(q, 1, false), 250);
    }

    async _fetch(q, page, append) {
        this._seq += 1;
        const mySeq = this._seq;
        this._query = q;
        this._page = page;

        let items = [];
        let hasMore = false;
        try {
            const url = new URL(this.endpointValue, window.location.origin);
            url.searchParams.set('q', q);
            url.searchParams.set('page', String(page));
            const resp = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (resp.ok) {
                const json = await resp.json();
                const data = json.data ?? json;
                items = Array.isArray(data) ? data : (Array.isArray(data.items) ? data.items : []);
                hasMore = true === (data.hasMore ?? false);
            }
        } catch (e) {
            // сеть недоступна → останется только «+ Создать»
        }
        if (mySeq !== this._seq) return;

        this._hasMore = hasMore;
        this._loadingMore = false;
        this._render(items, q, append);
    }

    _render(items, q, append) {
        if (!append) {
            this._menu.innerHTML = '';
            this._items = [];
            this._activeIndex = -1;
        }
        const startIdx = this._items.length;
        this._items = this._items.concat(items);

        items.forEach((it, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dropdown-item typeahead-item';
            const main = document.createElement('span');
            main.className = 'ta-main';
            main.textContent = it[this.mainFieldValue] ?? '';
            btn.appendChild(main);
            this.auxFieldsValue.forEach(a => {
                const val = it[a.field];
                if (val) {
                    const aux = document.createElement('span');
                    aux.className = 'ta-aux';
                    aux.textContent = (a.label ? a.label + ' ' : '') + val;
                    btn.appendChild(aux);
                }
            });
            const idx = startIdx + i;
            btn.addEventListener('mousedown', e => { e.preventDefault(); this._choose(this._items[idx]); });
            this._menu.appendChild(btn);
        });

        if (!append) {
            const exact = this._items.some(it => String(it[this.mainFieldValue] ?? '').toLocaleLowerCase() === q.toLocaleLowerCase());
            if (this.allowCreateValue && (this.createUrlValue || this.createModalValue) && !exact) {
                const c = document.createElement('button');
                c.type = 'button';
                c.className = 'dropdown-item typeahead-item text-primary';
                const span = document.createElement('span');
                span.textContent = `+ Создать «${q}»`;
                c.appendChild(span);
                c.addEventListener('mousedown', e => { e.preventDefault(); this._create(q); });
                this._menu.appendChild(c);
            }
            if (0 === this._menu.children.length) {
                const n = document.createElement('span');
                n.className = 'dropdown-item-text text-body-secondary';
                n.textContent = 'Ничего не найдено.';
                this._menu.appendChild(n);
            }
        }
        this._show();
    }

    _onScroll() {
        if (!this._hasMore || this._loadingMore) return;
        const m = this._menu;
        if (m.scrollTop + m.clientHeight >= m.scrollHeight - 24) {
            this._loadingMore = true;
            this._fetch(this._query, this._page + 1, true);
        }
    }

    _choose(item) {
        if (!item) return;
        this.element.value = item[this.mainFieldValue] ?? '';
        this._hidden.value = item.id;
        this._hiddenTitle.value = item[this.mainFieldValue] ?? '';
        this._clearHint();
        this._hide();
    }

    _create(title) {
        const trimmed = title.trim();
        if ('' === trimmed) return;
        this._hide();

        if (this.createModalValue) {
            this._openCreateModal(trimmed);
            return;
        }
        if (this.createUrlValue) {
            this._quickCreate(trimmed);
        }
    }

    async _quickCreate(title) {
        const body = { title };
        if ('' !== this.counterpartyNameValue) {
            const cp = document.querySelector(`input[name="${this.counterpartyNameValue}"]`);
            if (!cp || '' === cp.value) {
                alert('Сначала выберите заказчика — новый проект создаётся под ним.');
                return;
            }
            body.counterpartyId = cp.value;
        }

        try {
            const resp = await fetch(this.createUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            const json = await resp.json();
            if (201 === resp.status) {
                this._choose(json.data ?? json);
                return;
            }
            alert(json.message || 'Не удалось создать запись.');
        } catch (e) {
            alert('Сетевая ошибка при создании.');
        }
    }

    _openCreateModal(prefillTitle) {
        const el = document.querySelector(`[data-controller~="${this.createModalValue}"]`);
        const app = window.Stimulus;
        if (!el || !app) {
            alert('Форма создания недоступна на этой странице.');
            return;
        }
        const ctrl = app.getControllerForElementAndIdentifier(el, this.createModalValue);
        if (ctrl && typeof ctrl.open === 'function') ctrl.open(prefillTitle, this);
    }

    // Колбэк из модалки создания: подставить созданную сущность ({id, title, …}).
    addCreated(created) {
        this._choose(created);
    }

    _onKeydown(e) {
        if (!this._menu.classList.contains('show')) return;
        const btns = Array.from(this._menu.querySelectorAll('.dropdown-item'));
        if ('ArrowDown' === e.key) { e.preventDefault(); this._move(1, btns); }
        else if ('ArrowUp' === e.key) { e.preventDefault(); this._move(-1, btns); }
        else if ('Enter' === e.key) {
            if (this._activeIndex >= 0 && btns[this._activeIndex]) { e.preventDefault(); btns[this._activeIndex].dispatchEvent(new MouseEvent('mousedown')); }
        } else if ('Escape' === e.key) { this._hide(); }
    }

    _move(dir, btns) {
        if (0 === btns.length) return;
        this._activeIndex = (this._activeIndex + dir + btns.length) % btns.length;
        btns.forEach((b, i) => b.classList.toggle('active', i === this._activeIndex));
        btns[this._activeIndex].scrollIntoView({ block: 'nearest' });
    }

    _onBlur() {
        if ('' !== this.element.value.trim() && '' === this._hidden.value) {
            this._hint.classList.remove('d-none');
        }
    }

    _onDocPointer(e) {
        if (this.element.contains(e.target) || this._menu.contains(e.target)) return;
        this._hide();
    }

    _loadingState() {
        this._menu.innerHTML = '<span class="dropdown-item-text text-body-secondary">Идёт поиск…</span>';
        this._show();
    }

    _reposition() {
        const r = this.element.getBoundingClientRect();
        this._menu.style.position = 'fixed';
        this._menu.style.top = `${Math.round(r.bottom + 4)}px`;
        this._menu.style.left = `${Math.round(r.left)}px`;
        this._menu.style.width = `${Math.round(r.width)}px`;
    }

    _attachReposition() {
        window.addEventListener('scroll', this._reposition, true);
        window.addEventListener('resize', this._reposition);
    }

    _detachReposition() {
        window.removeEventListener('scroll', this._reposition, true);
        window.removeEventListener('resize', this._reposition);
    }

    _show() {
        this._reposition();
        this._menu.classList.add('show');
        this._attachReposition();
    }

    _hide() {
        this._menu.classList.remove('show');
        this._activeIndex = -1;
        this._detachReposition();
    }

    _clearHint() { this._hint.classList.add('d-none'); }
}
