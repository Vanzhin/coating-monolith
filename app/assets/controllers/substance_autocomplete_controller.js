import { Controller } from '@hotwired/stimulus';

/**
 * Typeahead поиска вещества. Когда сервер ничего не нашёл, показывает
 * inline мини-форму создания (canonical prefilled + CAS + alias),
 * шлёт POST на endpointCreate и подставляет свежесозданное вещество
 * в hidden input assessment-формы.
 *
 * ResponseListener в backend'е оборачивает JSON в { result, status, data, message },
 * поэтому распаковываем `.data`/`.message` fallback'ом на raw shape.
 */
export default class extends Controller {
    static values = {
        endpoint: String,
        endpointCreate: String,
    };
    static targets = ['input', 'hidden', 'results'];

    connect() {
        this.timer = null;
        this._onDocClick = (e) => {
            if (!this.element.contains(e.target)) {
                this._close();
            }
        };
        document.addEventListener('click', this._onDocClick);
    }

    disconnect() {
        document.removeEventListener('click', this._onDocClick);
        clearTimeout(this.timer);
    }

    onInput(evt) {
        clearTimeout(this.timer);
        // Пользователь начал печатать заново — прежний выбор больше не валиден.
        this.hiddenTarget.value = '';
        const q = evt.target.value.trim();
        if (!q) {
            this._close();
            return;
        }
        this.timer = setTimeout(() => this._query(q), 200);
    }

    async _query(q) {
        let resp;
        try {
            resp = await fetch(`${this.endpointValue}?q=${encodeURIComponent(q)}`);
        } catch {
            return;
        }
        if (!resp.ok) return;

        const raw = await resp.json();
        const items = raw?.data ?? raw;

        this.resultsTarget.innerHTML = '';

        if (Array.isArray(items) && items.length > 0) {
            items.forEach((it) => this.resultsTarget.appendChild(this._renderMatch(it)));
            this.resultsTarget.appendChild(this._renderDivider());
        }

        // «Ничего не найдено» + «Создать» доступны всегда, чтобы админ мог
        // ввести близкое к существующему название и всё равно создать новое.
        this.resultsTarget.appendChild(this._renderCreatePrompt(q));

        this._open();
    }

    _renderMatch(item) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dropdown-item';
        btn.textContent = item.canonicalName;
        if (item.cas) {
            const small = document.createElement('small');
            small.className = 'text-muted ms-2';
            small.textContent = 'CAS ' + item.cas;
            btn.appendChild(document.createTextNode(' '));
            btn.appendChild(small);
        }
        btn.addEventListener('click', () => this._select(item));
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

        wrap.appendChild(this._formField('Название', 'canonical', q, 'text', true));
        wrap.appendChild(this._formField('CAS (опц.)', 'cas', '', 'text', false, 'напр. 7732-18-5'));
        wrap.appendChild(this._formField('Псевдоним (опц.)', 'alias', '', 'text', false, 'напр. water'));

        const err = document.createElement('div');
        err.className = 'small text-danger mb-2 d-none';
        err.dataset.role = 'error';
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
        cancel.addEventListener('click', () => this._close());
        actions.appendChild(cancel);

        wrap.appendChild(actions);
        this.resultsTarget.appendChild(wrap);
        this._open();

        const inputs = wrap.querySelectorAll('input');
        save.addEventListener('click', () => this._submitCreate(wrap, err, save));
        inputs.forEach((inp) => {
            inp.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this._submitCreate(wrap, err, save);
                }
            });
        });
        inputs[0].focus();
    }

    _formField(label, name, value, type, required, placeholder = '') {
        const group = document.createElement('div');
        group.className = 'mb-2';

        const lbl = document.createElement('label');
        lbl.className = 'form-label small text-muted mb-1';
        lbl.textContent = label;
        group.appendChild(lbl);

        const input = document.createElement('input');
        input.type = type;
        input.className = 'form-control form-control-sm';
        input.dataset.field = name;
        input.value = value;
        if (required) input.required = true;
        if (placeholder) input.placeholder = placeholder;
        group.appendChild(input);

        return group;
    }

    async _submitCreate(wrap, err, saveBtn) {
        const canonicalName = wrap.querySelector('input[data-field="canonical"]').value.trim();
        const cas           = wrap.querySelector('input[data-field="cas"]').value.trim();
        const alias         = wrap.querySelector('input[data-field="alias"]').value.trim();

        if (canonicalName === '') {
            this._showError(err, 'Введите название.');
            return;
        }

        err.classList.add('d-none');
        saveBtn.disabled = true;

        let resp;
        try {
            resp = await fetch(this.endpointCreateValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    canonicalName,
                    cas:         cas || null,
                    aliasesText: alias,
                }),
            });
        } catch {
            saveBtn.disabled = false;
            this._showError(err, 'Сеть недоступна.');
            return;
        }

        const raw = await resp.json().catch(() => null);

        if (!resp.ok) {
            saveBtn.disabled = false;
            const message = raw?.message ?? 'Не удалось создать вещество.';
            this._showError(err, message);
            return;
        }

        const created = raw?.data ?? raw;
        this._select(created);
    }

    _select(item) {
        this.hiddenTarget.value = item.id;
        this.inputTarget.value  = item.canonicalName;
        this._close();
    }

    _showError(err, message) {
        err.textContent = message;
        err.classList.remove('d-none');
    }

    _open() {
        this.resultsTarget.classList.add('show');
        this.resultsTarget.style.position = 'absolute';
        this.resultsTarget.style.zIndex = '1000';
        this.resultsTarget.style.minWidth = '100%';
    }

    _close() {
        this.resultsTarget.innerHTML = '';
        this.resultsTarget.classList.remove('show');
    }

    _escape(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
}
