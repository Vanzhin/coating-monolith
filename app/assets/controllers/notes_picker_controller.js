import { Controller } from '@hotwired/stimulus';

/**
 * Мульти-выбор примечаний для assessment: chips + typeahead + inline
 * создание новой note. Форма отправляет обычные <input type="hidden"
 * name="noteIds[]" value="<uuid>"> — на сервере обработка не меняется.
 *
 * ResponseListener на бэке оборачивает JSON в {result,status,data,message},
 * поэтому распаковываем `.data`/`.message` fallback'ом на raw shape.
 */
export default class extends Controller {
    static values = {
        endpoint:       String,   // GET autocomplete
        endpointCreate: String,   // POST create
        fieldName:      { type: String, default: 'noteIds[]' },
        selected:       { type: Array, default: [] }, // [{id, title}]
    };
    static targets = ['input', 'results', 'chips', 'hidden'];

    connect() {
        this.timer = null;
        this.selected = [...this.selectedValue];
        this._renderChips();
        this._syncHidden();

        this._onDocClick = (e) => {
            if (!this.element.contains(e.target)) this._closeDropdown();
        };
        document.addEventListener('click', this._onDocClick);
    }

    disconnect() {
        document.removeEventListener('click', this._onDocClick);
        clearTimeout(this.timer);
    }

    onInput(evt) {
        clearTimeout(this.timer);
        const q = evt.target.value.trim();
        if (!q) {
            this._closeDropdown();
            return;
        }
        this.timer = setTimeout(() => this._search(q), 200);
    }

    async _search(q) {
        let resp;
        try {
            resp = await fetch(`${this.endpointValue}?q=${encodeURIComponent(q)}`);
        } catch { return; }
        if (!resp.ok) return;

        const raw   = await resp.json();
        const items = raw?.data ?? raw;
        const selectedIds = new Set(this.selected.map((n) => n.id));

        this.resultsTarget.innerHTML = '';

        const matches = Array.isArray(items)
            ? items.filter((n) => !selectedIds.has(n.id))
            : [];

        matches.forEach((n) => this.resultsTarget.appendChild(this._renderMatch(n)));

        if (matches.length > 0) {
            this.resultsTarget.appendChild(this._renderDivider());
        }

        this.resultsTarget.appendChild(this._renderCreatePrompt(q));
        this._openDropdown();
    }

    _renderMatch(note) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dropdown-item';
        btn.textContent = note.title;
        if (note.description) {
            const small = document.createElement('div');
            small.className = 'small text-muted';
            small.textContent = note.description;
            btn.appendChild(small);
        }
        btn.addEventListener('click', () => this._add(note));
        return btn;
    }

    _renderDivider() {
        const hr = document.createElement('div');
        hr.className = 'dropdown-divider';
        return hr;
    }

    _renderCreatePrompt(q) {
        const wrap = document.createElement('div');
        wrap.className = 'px-3 py-2';

        const note = document.createElement('div');
        note.className = 'small text-muted mb-2';
        note.textContent = 'Не нашли нужное?';
        wrap.appendChild(note);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-primary';
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> Создать «' + this._escape(q) + '»';
        btn.addEventListener('click', () => this._showCreateForm(q));
        wrap.appendChild(btn);

        return wrap;
    }

    _showCreateForm(q) {
        this.resultsTarget.innerHTML = '';

        const wrap = document.createElement('div');
        wrap.className = 'p-3';

        wrap.appendChild(this._formField('Заголовок', 'title', q, 'text', true));
        wrap.appendChild(this._formField('Описание', 'description', '', 'textarea', false));

        const err = document.createElement('div');
        err.className = 'small text-danger mb-2 d-none';
        wrap.appendChild(err);

        const actions = document.createElement('div');
        actions.className = 'd-flex gap-2';

        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'btn btn-sm btn-primary';
        save.textContent = 'Создать';
        actions.appendChild(save);

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-sm btn-outline-secondary';
        cancel.textContent = 'Отмена';
        cancel.addEventListener('click', () => this._closeDropdown());
        actions.appendChild(cancel);

        wrap.appendChild(actions);
        this.resultsTarget.appendChild(wrap);
        this._openDropdown();

        save.addEventListener('click', () => this._submitCreate(wrap, err, save));
        wrap.querySelectorAll('input, textarea').forEach((el) => {
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !(el.tagName === 'TEXTAREA' && !e.ctrlKey && !e.metaKey)) {
                    e.preventDefault();
                    this._submitCreate(wrap, err, save);
                }
            });
        });
        wrap.querySelector('[data-field="title"]').focus();
    }

    _formField(label, name, value, type, required) {
        const group = document.createElement('div');
        group.className = 'mb-2';

        const lbl = document.createElement('label');
        lbl.className = 'form-label small text-muted mb-1';
        lbl.textContent = label;
        group.appendChild(lbl);

        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.rows = 2;
        } else {
            input = document.createElement('input');
            input.type = type;
        }
        input.className = 'form-control form-control-sm';
        input.dataset.field = name;
        input.value = value;
        if (required) input.required = true;
        group.appendChild(input);

        return group;
    }

    async _submitCreate(wrap, err, saveBtn) {
        const title       = wrap.querySelector('[data-field="title"]').value.trim();
        const description = wrap.querySelector('[data-field="description"]').value.trim();

        if (title === '') {
            this._showError(err, 'Введите заголовок.');
            return;
        }

        err.classList.add('d-none');
        saveBtn.disabled = true;

        let resp;
        try {
            resp = await fetch(this.endpointCreateValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, description }),
            });
        } catch {
            saveBtn.disabled = false;
            this._showError(err, 'Сеть недоступна.');
            return;
        }

        const raw = await resp.json().catch(() => null);
        if (!resp.ok) {
            saveBtn.disabled = false;
            this._showError(err, raw?.message ?? 'Не удалось создать примечание.');
            return;
        }

        this._add(raw?.data ?? raw);
    }

    _add(note) {
        if (this.selected.some((n) => n.id === note.id)) return;
        this.selected.push({ id: note.id, title: note.title });
        this._renderChips();
        this._syncHidden();
        this.inputTarget.value = '';
        this._closeDropdown();
    }

    _remove(id) {
        this.selected = this.selected.filter((n) => n.id !== id);
        this._renderChips();
        this._syncHidden();
    }

    _renderChips() {
        this.chipsTarget.innerHTML = '';
        this.selected.forEach((n) => {
            const chip = document.createElement('span');
            chip.className = 'badge text-bg-light fw-normal d-inline-flex align-items-center gap-1';
            chip.textContent = n.title;

            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'btn-close btn-close-sm ms-1';
            x.setAttribute('aria-label', 'Убрать');
            x.addEventListener('click', () => this._remove(n.id));
            chip.appendChild(x);

            this.chipsTarget.appendChild(chip);
        });
    }

    _syncHidden() {
        this.hiddenTarget.innerHTML = '';
        this.selected.forEach((n) => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = this.fieldNameValue;
            inp.value = n.id;
            this.hiddenTarget.appendChild(inp);
        });
    }

    _showError(err, message) {
        err.textContent = message;
        err.classList.remove('d-none');
    }

    _openDropdown() {
        this.resultsTarget.classList.add('show');
        this.resultsTarget.style.position = 'absolute';
        this.resultsTarget.style.zIndex = '1000';
        this.resultsTarget.style.minWidth = '100%';
    }

    _closeDropdown() {
        this.resultsTarget.innerHTML = '';
        this.resultsTarget.classList.remove('show');
    }

    _escape(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
}
