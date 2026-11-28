import { Controller } from '@hotwired/stimulus';

const locationFields = ['locationId', 'country', 'city', 'cityType', 'street', 'house', 'enterprise'];
const settlementAbbreviations = { city: 'г.', village: 'д.', selo: 'с.', settlement: 'п.', khutor: 'х.', urban_settlement: 'пгт' };
const tripAnimationDuration = 200;

export default class extends Controller {
    static targets = ['end', 'startOdometer', 'distance', 'calculated', 'difference', 'differenceText'];
    static values = { start: String, initialLocation: Object };

    connect() {
        this.tripStates = new WeakMap();
        this.tripDetails().forEach((details) => this.prepareTrip(details));
        this.refreshTrips();
        this.refreshRefuelings();
        this.refreshCollectionState('absence');
    }

    disconnect() {
        const requested = this.tripDetails().find((details) => this.tripState(details).requestedOpen);
        this.tripDetails().forEach((details) => {
            this.cancelTripAnimation(details);
            const content = this.tripContent(details);
            details.open = details === requested;
            if (content) content.inert = !details.open;
        });
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
        if (added) this.prepareTrip(added.querySelector('details'));

        this.renumber(kind);
        if (kind === 'trip') this.refreshTrips();
        if (kind === 'refueling') this.refreshRefuelings();
        if (kind === 'absence') this.refreshCollectionState('absence');
    }

    remove(event) {
        const kind = event.params.kind;
        const row = event.currentTarget.closest(`[data-waybill-form-target~="${kind}Row"]`);
        if (!row) return;

        const details = row.querySelector?.('details');
        if (details) this.cancelTripAnimation(details);
        row.remove();
        this.renumber(kind);
        if (kind === 'trip') this.refreshTrips();
        if (kind === 'refueling') this.refreshRefuelings();
        if (kind === 'absence') this.refreshCollectionState('absence');
    }

    insert(event) {
        const row = event.currentTarget.closest('[data-waybill-form-target~="tripRow"]');
        const template = this.template('trip');
        if (!row || !template) return;

        row.after(template.content.cloneNode(true));
        const inserted = row.nextElementSibling;
        if (!inserted) return;
        this.prepareTrip(inserted.querySelector('details'));
        this.input(inserted, 'date').value = this.input(row, 'date').value;
        this.renumber('trip');
        this.refreshTrips();
        inserted.querySelector('summary')?.focus();
    }

    toggleTrip(event) {
        event.preventDefault();
        const details = event.currentTarget.closest('details');
        if (!details) return;
        this.requestTrip(details, !this.tripState(details).requestedOpen);
    }

    requestTrip(details, open) {
        const state = this.tripState(details);
        state.requestedOpen = open;
        const revision = ++state.revision;
        if (!open) return this.animateTrip(details, open);

        const closing = this.tripDetails()
            .filter((other) => other !== details && (other.open || this.tripState(other).requestedOpen))
            .map((other) => this.requestTrip(other, false));
        if (!closing.length) return this.animateTrip(details, open);

        return Promise.all(closing).then(() => {
            if (state.revision !== revision || !state.requestedOpen || details.isConnected === false) return false;
            return this.animateTrip(details, true);
        });
    }

    prepareTrip(details) {
        if (!details) return;
        this.tripState(details);
        const content = this.tripContent(details);
        if (content) content.inert = !details.open;
    }

    tripState(details) {
        this.tripStates ||= new WeakMap();
        let state = this.tripStates.get(details);
        if (!state) {
            state = { requestedOpen: details.open, revision: 0, animation: null };
            this.tripStates.set(details, state);
        }
        return state;
    }

    tripContent(details) {
        return details.querySelector?.('.waybill-trip-content') || null;
    }

    cancelTripAnimation(details) {
        const state = this.tripState(details);
        state.requestedOpen = false;
        state.revision++;
        if (state.animation) state.animation.cancel();
        state.animation = null;
        const content = this.tripContent(details);
        if (content) this.clearTripContent(content);
    }

    animateTrip(details, open) {
        const state = this.tripState(details);
        const content = this.tripContent(details);
        if (!content) {
            details.open = open;
            return Promise.resolve(true);
        }

        const startHeight = details.open ? content.getBoundingClientRect().height : 0;
        if (state.animation) state.animation.cancel();
        state.animation = null;
        content.style.height = `${startHeight}px`;
        content.style.overflow = 'hidden';

        if (!open) {
            const activeElement = details.ownerDocument?.activeElement;
            if (activeElement && content.contains?.(activeElement)) details.querySelector('summary')?.focus();
            content.inert = true;
        } else {
            details.open = true;
            content.inert = false;
        }

        const endHeight = open ? content.scrollHeight : 0;
        const reducedMotion = details.ownerDocument?.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion || typeof content.animate !== 'function' || startHeight === endHeight) {
            this.clearTripContent(content);
            details.open = open;
            content.inert = !open;
            return Promise.resolve(true);
        }

        const animation = content.animate([
            { height: `${startHeight}px`, overflow: 'hidden' },
            { height: `${endHeight}px`, overflow: 'hidden' },
        ], { duration: tripAnimationDuration, easing: 'ease', fill: 'forwards' });
        state.animation = animation;

        return new Promise((resolve) => {
            animation.oncancel = () => resolve(false);
            animation.onfinish = () => {
                if (state.animation !== animation) {
                    resolve(false);
                    return;
                }
                state.animation = null;
                animation.oncancel = null;
                animation.cancel();
                this.clearTripContent(content);
                details.open = open;
                content.inert = !open;
                resolve(true);
            };
        });
    }

    clearTripContent(content) {
        content.style.height = '';
        content.style.overflow = '';
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
        this.refreshTripCollectionState();
        if (recalculate) this.recalculate();
    }

    refreshTripLocations() {
        this.refreshTrips(false);
    }

    refreshRefuelings() {
        if (!this.element) return;
        for (const row of this.refuelingRows()) this.updateRefuelingSummary(row);
        this.refreshCollectionState('refueling');
        this.refreshRefueledTotal();
    }

    refreshCollectionState(kind) {
        if (!this.element) return;
        const rows = this.rows(kind);
        const empty = this.element.querySelector(`[data-waybill-form-target="${kind}Empty"]`);
        if (rows && empty) empty.hidden = Boolean(rows.querySelector(`[data-waybill-form-target~="${kind}Row"]`));
    }

    refreshTripCollectionState() {
        this.refreshCollectionState('trip');
        if (!this.element) return;
        const count = this.element.querySelector('[data-waybill-form-target="tripCount"]');
        if (count) count.textContent = String(this.tripRows().length);
    }

    refreshRefuelingSummary(event) {
        const row = event.currentTarget.closest('[data-waybill-form-target~="refuelingRow"]');
        if (!row) return;
        this.updateRefuelingSummary(row);
        this.refreshRefueledTotal();
    }

    updateRefuelingSummary(row) {
        const date = row.querySelector('[data-role="refueling-date"]');
        const fuelType = row.querySelector('[data-role="refueling-fuel-type"]');
        const quantity = row.querySelector('[data-role="refueling-quantity"]');
        if (!date || !fuelType || !quantity) return;

        const dateValue = this.input(row, 'date').value;
        const fuelTypeValue = this.input(row, 'fuelType').value;
        const quantityValue = this.input(row, 'quantity').value;
        const [day] = dateValue.split('T');
        date.textContent = dateValue ? day.split('-').reverse().join('.') : 'Новая заправка';
        fuelType.textContent = fuelTypeValue || 'Вид топлива не выбран';
        quantity.textContent = quantityValue ? `${quantityValue} л` : '0 л';
    }

    refuelingRows() {
        const rows = this.rows('refueling');

        return rows ? Array.from(rows.querySelectorAll('[data-waybill-form-target~="refuelingRow"]')) : [];
    }

    refreshRefueledTotal() {
        if (!this.element) return;
        const target = this.element.querySelector('[data-waybill-form-target="refueledTotal"]');
        if (!target) return;
        const total = this.refueledTotal();
        target.textContent = total === null ? '—' : `+${total} л`;
    }

    refueledTotal() {
        const quantities = this.refuelingRows()
            .map((row) => this.input(row, 'quantity').value.trim())
            .filter((value) => value !== '');

        return this.decimalTotal(quantities);
    }

    decimalTotal(values) {
        const parts = [];
        let scale = 0;
        for (const raw of values) {
            const value = raw.replace(',', '.');
            if (!/^\d+(?:\.\d+)?$/.test(value)) return null;
            const [whole, fraction = ''] = value.split('.');
            parts.push({ whole, fraction });
            scale = Math.max(scale, fraction.length);
        }

        let total = 0n;
        for (const part of parts) {
            const units = BigInt(`${part.whole}${part.fraction}`);
            total += units * BigInt(`1${'0'.repeat(scale - part.fraction.length)}`);
        }
        const digits = total.toString().padStart(scale + 1, '0');
        const whole = scale ? digits.slice(0, -scale) : digits;
        const fraction = scale ? digits.slice(-scale).replace(/0+$/, '') : '';

        return fraction ? `${whole},${fraction}` : whole;
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
        const distanceValue = this.input(row, 'distance').value;
        const destinationText = this.locationText(destination);
        const hasContent = Boolean(dateValue || distanceValue || destinationText);
        row.classList.toggle('wb-trip--has-content', hasContent);
        date.textContent = dateValue ? dateValue.split('-').reverse().join('.') : '';
        distance.textContent = distanceValue ? `${distanceValue} км` : '';
        location.textContent = hasContent ? destinationText : 'Новая поездка';
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

        return text;
    }

    recalculate() {
        const start = BigInt(this.startValue);
        this.startOdometerTarget.textContent = this.formatKilometers(start);
        const distance = this.tripDistance();
        if (distance === null) {
            this.distanceTarget.textContent = '—';
            this.calculatedTarget.textContent = '—';
            this.updateDifferenceBadge('Расхождение: —');
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
            this.updateDifferenceBadge('Расхождение: —');
            return;
        }

        const difference = BigInt(target) - calculated;
        const sign = difference > 0n ? '+' : '';
        const tone = difference === 0n ? 'ok' : 'bad';
        this.updateDifferenceBadge(`Расхождение: ${sign}${this.formatKilometers(difference)}`, tone);
    }

    updateDifferenceBadge(text, tone = 'neutral') {
        const icons = {
            neutral: 'bi bi-dash-circle',
            ok: 'bi bi-check-circle-fill',
            bad: 'bi bi-exclamation-circle-fill',
        };
        if (this.differenceTextTarget) {
            this.differenceTextTarget.textContent = text;
        } else {
            this.differenceTarget.textContent = text;
        }
        this.differenceTarget.classList?.remove('wb-diff--neutral', 'wb-diff--ok', 'wb-diff--bad');
        this.differenceTarget.classList?.add(`wb-diff--${tone}`);
        const icon = this.differenceTarget.querySelector?.('[data-role="difference-icon"]');
        if (icon) icon.className = icons[tone];
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

    tripDetails() {
        return this.tripRows().map((row) => row.querySelector('details')).filter(Boolean);
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
            row.querySelectorAll('[id], [for], [aria-controls], [aria-labelledby], [aria-describedby]').forEach((element) => {
                if (element.id) element.id = element.id.replace(/(?:\d+|__i__)$/, String(index));
                for (const attribute of ['for', 'aria-controls', 'aria-labelledby', 'aria-describedby']) {
                    const value = element.getAttribute(attribute);
                    if (!value) continue;
                    const references = value.split(/\s+/).map((reference) => reference.replace(/(?:\d+|__i__)$/, String(index)));
                    element.setAttribute(attribute, references.join(' '));
                }
            });
            const title = row.querySelector('[data-role="row-title"]');
            if (title) title.textContent = `${row.dataset.rowLabel} ${index + 1}`;
        });
    }
}
