import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { endpoint: String };
    static targets = ['input', 'hidden', 'results'];

    connect() {
        this.timer = null;
        // Close dropdown when clicking outside
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

        const items = await resp.json();
        if (items.length === 0) {
            this._close();
            return;
        }

        this.resultsTarget.innerHTML = items.map(it =>
            `<button type="button" class="dropdown-item" data-id="${it.id}" data-canonical="${it.canonicalName}">` +
            `${it.canonicalName}${it.cas ? ` <small class="text-muted">CAS ${it.cas}</small>` : ''}` +
            `</button>`
        ).join('');

        this.resultsTarget.classList.add('show');
        this.resultsTarget.style.position = 'absolute';
        this.resultsTarget.style.zIndex = '1000';
        this.resultsTarget.style.minWidth = '100%';

        this.resultsTarget.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                this.hiddenTarget.value = btn.dataset.id;
                this.inputTarget.value = btn.dataset.canonical;
                this._close();
            });
        });
    }

    _close() {
        this.resultsTarget.innerHTML = '';
        this.resultsTarget.classList.remove('show');
    }
}
