import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['country', 'city', 'cityType', 'street', 'house', 'enterprise', 'latitude', 'longitude'];

    select(event) {
        const location = event.detail;
        for (const field of this.constructor.targets) {
            const target = `has${field.charAt(0).toUpperCase() + field.slice(1)}Target`;
            if (!this[target]) continue;
            const input = this[`${field}Target`];
            input.value = location[field] ?? '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
}
