import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { coatingId: String, total: Number };
    static targets = ['tbody', 'search', 'loadAllBtn'];

    connect() {
        this.debounceTimer = null;
        const modal = this.element.closest('.modal');
        if (modal) {
            this.modalShownHandler = () => {
                const highlight = modal.getAttribute('data-highlight-substance-id');
                if (highlight) this.loadForHighlight(highlight);
            };
            modal.addEventListener('shown.bs.modal', this.modalShownHandler);
        }
    }

    disconnect() {
        const modal = this.element.closest('.modal');
        if (modal && this.modalShownHandler) {
            modal.removeEventListener('shown.bs.modal', this.modalShownHandler);
        }
    }

    onSearchInput(event) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => this.fetch(event.target.value, 1, 50), 200);
    }

    loadAll() {
        this.fetch(this.searchTarget.value || '', 1, this.totalValue).then(() => {
            if (this.hasLoadAllBtnTarget) this.loadAllBtnTarget.style.display = 'none';
        });
    }

    async loadForHighlight(substanceId) {
        await this.fetch('', 1, this.totalValue, substanceId);
        if (this.hasLoadAllBtnTarget) this.loadAllBtnTarget.style.display = 'none';
        const row = this.tbodyTarget.querySelector(`tr[data-substance-id="${substanceId}"]`);
        if (row) {
            row.classList.add('table-warning');
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    async fetch(search, page, pageSize, highlight = null) {
        const params = new URLSearchParams({ page: String(page), pageSize: String(pageSize) });
        if (search) params.set('search', search);
        if (highlight) params.set('highlight', highlight);
        const url = `/cabinet/coatings/${this.coatingIdValue}/chem-resistance/partial?${params}`;
        const resp = await fetch(url, { headers: { Accept: 'text/html' } });
        if (!resp.ok) return;
        this.tbodyTarget.innerHTML = await resp.text();
        // Re-initialise Bootstrap tooltips on freshly rendered content.
        if (window.bootstrap && window.bootstrap.Tooltip) {
            this.tbodyTarget.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(
                el => new window.bootstrap.Tooltip(el),
            );
        }
    }
}
