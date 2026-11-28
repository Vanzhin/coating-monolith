import { Controller } from '@hotwired/stimulus';

const addressFields = ['country', 'city', 'cityType', 'street', 'house', 'enterprise'];

export default class extends Controller {
    static targets = [...addressFields, 'locationId', 'searchInput', 'clearButton'];
    static values = { card: Boolean };

    connect() {
        this.refresh();
    }

    select(event) {
        const location = event.detail;
        for (const field of addressFields) {
            const target = `has${field.charAt(0).toUpperCase() + field.slice(1)}Target`;
            if (!this[target]) continue;
            const input = this[`${field}Target`];
            input.value = location[field] ?? '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this.refresh();
    }

    addCreated(location) {
        this.locationIdTarget.value = location.id ?? '';
        this.locationIdTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.select({ detail: location });
    }

    clear() {
        if (!this.cardValue) return;
        for (const field of ['locationId', ...addressFields]) {
            const input = this[`${field}Target`];
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (field === 'locationId') input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (this.hasSearchInputTarget) this.searchInputTarget.value = '';
        this.refresh();
    }

    refresh() {
        if (!this.cardValue || !this.hasClearButtonTarget) return;
        this.clearButtonTarget.hidden = !this.hasLocation();
    }

    hasLocation() {
        if (this.hasLocationIdTarget && this.locationIdTarget.value) return true;

        return addressFields.some((field) => this[`${field}Target`].value.trim() !== '');
    }
}
