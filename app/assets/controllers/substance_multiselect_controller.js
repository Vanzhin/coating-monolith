import { Controller } from '@hotwired/stimulus';

/**
 * Мультивыбор веществ для страницы «Химстойкость». Чипы выбранных веществ
 * рендерит сервер (hidden `substanceIds[]` внутри формы, состояние — в URL).
 * Контроллер добавляет вещество (выбор из подсказок) и убирает по ×, каждый раз
 * пересабмичивая GET-форму — страница перезагружается с новым набором чипов.
 * Так поиск работает и без JS (обычный GET), JS лишь ускоряет ввод.
 *
 * Создание новых веществ тут НЕ делаем — только выбор существующих (завести
 * вещество можно в справочнике «Вещества»).
 */
export default class extends Controller {
    static values = { endpoint: String };
    static targets = ['input', 'results'];

    connect() {
        this.timer = null;
        this._onDocClick = (e) => {
            if (!this.element.contains(e.target)) this._close();
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
            this._close();
            return;
        }
        this.timer = setTimeout(() => this._query(q), 200);
    }

    // Enter в поле не сабмитит форму — вещество выбирают из подсказок.
    onKeydown(evt) {
        if (evt.key === 'Enter') evt.preventDefault();
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
        const selected = this._selectedIds();

        this.resultsTarget.innerHTML = '';
        const fresh = Array.isArray(items) ? items.filter((it) => !selected.includes(it.id)) : [];

        if (fresh.length > 0) {
            fresh.forEach((it) => this.resultsTarget.appendChild(this._renderMatch(it)));
        } else {
            this.resultsTarget.appendChild(this._renderNote('Ничего не найдено'));
        }

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
        btn.addEventListener('click', () => this._add(item.id));
        return btn;
    }

    _renderNote(text) {
        const div = document.createElement('div');
        div.className = 'px-3 py-2 small text-muted';
        div.textContent = text;
        return div;
    }

    // Добавить вещество: дописать hidden substanceIds[] в форму и пересабмитить.
    _add(id) {
        if (!id || this._selectedIds().includes(id)) {
            this._close();
            return;
        }
        const form = this.element.closest('form');
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'substanceIds[]';
        hidden.value = id;
        form.appendChild(hidden);
        this._submit(form);
    }

    _submit(form) {
        if (!form) return;
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    }

    _selectedIds() {
        const form = this.element.closest('form');
        if (!form) return [];
        return Array.from(form.querySelectorAll('input[name="substanceIds[]"]')).map((i) => i.value);
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
}
