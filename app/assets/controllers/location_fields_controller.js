import { Controller } from '@hotwired/stimulus';

const addressFields = ['country', 'city', 'cityType', 'street', 'house', 'enterprise'];
const primaryAddressFields = ['country', 'city', 'street', 'house'];
const auxiliaryAddressFields = ['cityType', 'enterprise', 'locationId'];
const locationTextFields = ['country', 'city', 'street', 'house', 'enterprise'];

export default class extends Controller {
    static targets = [...addressFields, 'locationId', 'summaryText', 'findButton', 'clearButton', 'cardPanel', 'fieldsPanel'];
    static values = { card: Boolean, createUrl: String };

    connect() {
        if (!this.cardValue || this.hasOnlyAuxiliaryLocationData()) return;
        this.refresh();
        this.fieldsPanelTarget.hidden = true;
        this.cardPanelTarget.hidden = false;
    }

    hasOnlyAuxiliaryLocationData() {
        const hasPrimaryAddress = primaryAddressFields.some((field) => this[`${field}Target`].value.trim() !== '');
        if (hasPrimaryAddress) return false;

        return auxiliaryAddressFields.some((field) => this[`${field}Target`].value.trim() !== '');
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
        if (this.cardValue) this.refresh();
    }

    addCreated(location) {
        this.locationIdTarget.value = location.id ?? '';
        this.locationIdTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.select({ detail: location });
    }

    find() {
        if (!this.cardValue) return;
        const element = document.querySelector('[data-controller~="location-create-modal"]');
        const modal = element ? this.application.getControllerForElementAndIdentifier(element, 'location-create-modal') : null;
        if (modal && typeof modal.openPicker === 'function') modal.openPicker(this);
    }

    clear() {
        if (!this.cardValue) return;
        for (const field of ['locationId', ...addressFields]) {
            const input = this[`${field}Target`];
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (field === 'locationId') input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this.refresh();
    }

    refresh() {
        if (!this.cardValue || !this.hasSummaryTextTarget) return;
        const filled = this.hasLocation();
        this.summaryTextTarget.textContent = this.locationText();
        this.findButtonTarget.hidden = filled;
        this.clearButtonTarget.hidden = !filled;
    }

    hasLocation() {
        if (this.hasLocationIdTarget && this.locationIdTarget.value) return true;

        return addressFields.some((field) => this[`${field}Target`].value.trim() !== '');
    }

    locationText() {
        if (!this.hasLocation()) return 'Не указана';
        const text = locationTextFields
            .map((field) => this[`${field}Target`].value.trim())
            .filter(Boolean)
            .join(', ');

        return text || 'Не указана';
    }
}
