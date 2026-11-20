import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['end'];
    static values = { start: String, initialLocation: Object };

    connect() {
        this.refreshTrips();
    }

    add(event) {
        const kind = event.params.kind;
        const rows = this.rows(kind);
        const template = this.template(kind);
        if (!rows || !template) return;

        if (kind === 'trip') this.refreshTrips();
        const source = kind === 'trip' ? this.lastTripRow() : null;
        rows.appendChild(template.content.cloneNode(true));
        const added = kind === 'trip' ? rows.lastElementChild : null;
        if (source && added) this.copyReturnTrip(source, added);

        this.renumber(kind);
        if (kind === 'trip') this.refreshTrips();
    }

    remove(event) {
        const kind = event.params.kind;
        const row = event.currentTarget.closest(`[data-waybill-form-target~="${kind}Row"]`);
        if (!row) return;

        row.remove();
        this.renumber(kind);
        if (kind === 'trip') this.refreshTrips();
    }

    insert(event) {
        const row = event.currentTarget.closest('[data-waybill-form-target~="tripRow"]');
        const template = this.template('trip');
        if (!row || !template) return;

        row.after(template.content.cloneNode(true));
        const inserted = row.nextElementSibling;
        if (!inserted) return;
        this.input(inserted, 'date').value = this.input(row, 'date').value;
        this.renumber('trip');
        this.refreshTrips();
        this.input(inserted, 'city').focus();
    }

    refreshTrips() {
        let departure = { ...this.initialLocationValue, enterprise: this.initialLocationValue.enterprise || '' };
        this.departures = new Map();
        const rows = this.tripRows();
        for (const [index, row] of rows.entries()) {
            this.departures.set(row, departure);
            const label = row.querySelector('[data-role="departure"]');
            if (label) label.textContent = this.locationText(departure);
            const insertion = row.querySelector('[data-role="trip-insertion"]');
            if (insertion) insertion.hidden = index === rows.length - 1;
            departure = this.destination(row);
        }
        this.recalculate();
    }

    copyReturnTrip(source, added) {
        const destination = this.departures.get(source);
        if (!destination) return;

        for (const field of ['country', 'city', 'street', 'house', 'enterprise']) {
            this.input(added, field).value = destination[field] || '';
        }
        this.input(added, 'distance').value = this.input(source, 'distance').value;
        this.input(added, 'date').value = this.input(source, 'date').value;
    }

    destination(row) {
        const destination = {};
        for (const field of ['country', 'city', 'street', 'house', 'enterprise']) {
            destination[field] = this.input(row, field).value;
        }

        return destination;
    }

    locationText(location) {
        const text = ['country', 'city', 'street', 'house', 'enterprise']
            .map((field) => (location[field] || '').trim())
            .filter(Boolean)
            .join(', ');

        return text || 'Не указана';
    }

    recalculate() {
        let odometer = BigInt(this.startValue);
        for (const row of this.tripRows()) {
            const input = this.input(row, 'distance');
            if (input.value === '') continue;
            if (!/^[0-9]+$/.test(input.value) || BigInt(input.value) === 0n) return;
            odometer += BigInt(input.value);
        }
        this.endTarget.value = odometer.toString();
    }

    rows(kind) {
        return this.element.querySelector(`[data-waybill-form-target="${kind}Rows"]`);
    }

    tripRows() {
        const rows = this.rows('trip');

        return rows ? Array.from(rows.querySelectorAll('[data-waybill-form-target~="tripRow"]')) : [];
    }

    lastTripRow() {
        const rows = this.tripRows();

        return rows[rows.length - 1] || null;
    }

    template(kind) {
        return this.element.querySelector(`template[data-waybill-form-target="${kind}Template"]`);
    }

    input(row, field) {
        return row.querySelector(`[name$="[${field}]"]`);
    }

    renumber(kind) {
        const rows = this.rows(kind);
        if (!rows) return;
        rows.querySelectorAll(`[data-waybill-form-target~="${kind}Row"]`).forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((input) => {
                input.name = input.name.replace(/\[(?:\d+|__i__)\]/, `[${index}]`);
            });
            row.querySelectorAll('[id], [for]').forEach((element) => {
                if (element.id) element.id = element.id.replace(/(?:\d+|__i__)$/, String(index));
                if (element.htmlFor) element.htmlFor = element.htmlFor.replace(/(?:\d+|__i__)$/, String(index));
            });
            const title = row.querySelector('[data-role="row-title"]');
            if (title) title.textContent = `${row.dataset.rowLabel} ${index + 1}`;
        });
    }
}
