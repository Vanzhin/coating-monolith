import { Controller } from '@hotwired/stimulus';

const locationFields = ['locationId', 'country', 'city', 'cityType', 'street', 'house', 'enterprise'];
const settlementAbbreviations = { city: 'г.', village: 'д.', selo: 'с.', settlement: 'п.', khutor: 'х.', urban_settlement: 'пгт' };

export default class extends Controller {
    static targets = ['end', 'startOdometer', 'distance', 'calculated', 'difference'];
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
        inserted.querySelector('summary')?.focus();
    }

    refreshTrips(recalculate = true) {
        let departure = { ...this.initialLocationValue, locationId: this.initialLocationValue.id || '', enterprise: this.initialLocationValue.enterprise || '' };
        this.departures = new Map();
        const rows = this.tripRows();
        for (const [index, row] of rows.entries()) {
            this.departures.set(row, departure);
            row.querySelectorAll('[data-role="departure"]').forEach((label) => {
                label.textContent = this.locationText(departure);
            });
            const insertion = row.querySelector('[data-role="trip-insertion"]');
            if (insertion) insertion.hidden = index === rows.length - 1;
            departure = this.destination(row);
            this.updateTripSummary(row, departure);
        }
        if (recalculate) this.recalculate();
    }

    refreshTripLocations() {
        this.refreshTrips(false);
    }

    refreshTripSummary(event) {
        const row = event.currentTarget.closest('[data-waybill-form-target~="tripRow"]');
        if (row) this.updateTripSummary(row, this.destination(row));
    }

    updateTripSummary(row, destination) {
        const date = row.querySelector('[data-role="trip-date"]');
        const distance = row.querySelector('[data-role="trip-distance"]');
        const location = row.querySelector('[data-role="destination"]');
        if (!date || !distance || !location) return;

        const dateValue = this.input(row, 'date').value;
        date.textContent = dateValue ? dateValue.split('-').reverse().join('.') : 'Дата не указана';
        const distanceValue = this.input(row, 'distance').value;
        distance.textContent = distanceValue ? `${distanceValue} км` : 'Расстояние не указано';
        location.textContent = this.locationText(destination);
    }

    copyReturnTrip(source, added) {
        const destination = this.departures.get(source);
        if (!destination) return;

        for (const field of locationFields) {
            this.input(added, field).value = destination[field] || '';
        }
        this.input(added, 'distance').value = this.input(source, 'distance').value;
        this.input(added, 'date').value = this.input(source, 'date').value;
    }

    destination(row) {
        const destination = {};
        for (const field of locationFields) {
            destination[field] = this.input(row, field).value;
        }

        return destination;
    }

    locationText(location) {
        const abbreviation = settlementAbbreviations[location.cityType] || '';
        const city = `${abbreviation} ${location.city || ''}`.trim();
        const text = [location.country, city, location.street, location.house, location.enterprise]
            .map((part) => (part || '').trim())
            .filter(Boolean)
            .join(', ');

        return text || 'Не указана';
    }

    recalculate() {
        const start = BigInt(this.startValue);
        this.startOdometerTarget.textContent = this.formatKilometers(start);
        const distance = this.tripDistance();
        if (distance === null) {
            this.distanceTarget.textContent = '—';
            this.calculatedTarget.textContent = '—';
            this.differenceTarget.textContent = 'Расхождение: —';
            return;
        }

        const calculated = start + distance;
        this.distanceTarget.textContent = this.formatKilometers(distance);
        this.calculatedTarget.textContent = this.formatKilometers(calculated);
        this.updateDifference(calculated);
    }

    tripDistance() {
        let distance = 0n;
        for (const row of this.tripRows()) {
            const value = this.input(row, 'distance').value;
            if (!/^[0-9]+$/.test(value)) return null;
            const tripDistance = BigInt(value);
            if (tripDistance === 0n) return null;
            distance += tripDistance;
        }

        return distance;
    }

    updateDifference(calculated) {
        const target = this.endTarget.value.trim();
        if (!/^[0-9]+$/.test(target)) {
            this.differenceTarget.textContent = 'Расхождение: —';
            return;
        }

        const difference = BigInt(target) - calculated;
        const sign = difference > 0n ? '+' : '';
        this.differenceTarget.textContent = `Расхождение: ${sign}${this.formatKilometers(difference)}`;
    }

    formatKilometers(value) {
        return `${value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ')} км`;
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
            row.querySelectorAll('[data-typeahead-hidden-name-value]').forEach((typeahead) => {
                const hiddenName = typeahead.getAttribute('data-typeahead-hidden-name-value');
                typeahead.setAttribute('data-typeahead-hidden-name-value', hiddenName.replace(/\[(?:\d+|__i__)\]/, `[${index}]`));
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
