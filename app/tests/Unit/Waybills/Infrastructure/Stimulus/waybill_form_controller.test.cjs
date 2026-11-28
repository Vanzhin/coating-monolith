const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/waybill_form_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class WaybillFormController extends Controller');
const WaybillFormController = vm.runInNewContext(`${source}\nWaybillFormController;`);

function trip(fields) {
    const inputs = Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, { value, focused: false, focus() { this.focused = true; } }]));
    const summary = Object.fromEntries(['trip-date', 'trip-distance', 'destination'].map((key) => [key, { textContent: '' }]));
    const summaryElement = { focused: false, focus() { this.focused = true; } };
    const details = { open: false };
    const departures = [{ textContent: '' }, { textContent: '' }];
    const insertion = { hidden: false };
    const classes = new Set();
    const classList = { toggle(name, force) { if (force) classes.add(name); else classes.delete(name); }, contains(name) { return classes.has(name); } };
    return {
        inputs, summary, summaryElement, details, departures, insertion, classList,
        querySelector(selector) {
            if (selector === 'summary') return summaryElement;
            if (selector === 'details') return this.details;
            const field = selector.match(/^\[name\$="\[(.+)\]"\]$/)?.[1];
            if (field) return inputs[field];
            const role = selector.match(/^\[data-role="(.+)"\]$/)?.[1];
            if (role === 'trip-insertion') return insertion;
            return summary[role] ?? null;
        },
        querySelectorAll(selector) { return selector === '[data-role="departure"]' ? departures : []; },
    };
}

function routeTrip(distance, fields = {}) {
    return trip({ date: '2026-11-01', distance, locationId: '', country: 'Россия', cityType: 'city', city: '', street: '', house: '', enterprise: '', ...fields });
}

function odometerPreview(rows, start = '100', end = '') {
    const controller = new WaybillFormController();
    controller.startValue = start;
    controller.endTarget = { value: end };
    controller.startOdometerTarget = { textContent: '' };
    controller.distanceTarget = { textContent: '' };
    controller.calculatedTarget = { textContent: '' };
    controller.differenceTarget = { textContent: '' };
    controller.tripRows = () => rows;
    return controller;
}

function assertOdometerPreview(controller, start, distance, calculated, difference) {
    assert.equal(controller.startOdometerTarget.textContent, start);
    assert.equal(controller.distanceTarget.textContent, distance);
    assert.equal(controller.calculatedTarget.textContent, calculated);
    assert.equal(controller.differenceTarget.textContent, difference);
}

test('summary shows current date, distance and destination, including empty values', () => {
    const controller = new WaybillFormController();
    const row = trip({ date: '2026-11-03', distance: '18' });
    controller.updateTripSummary(row, { country: 'Россия', cityType: 'city', city: 'Мытищи', street: 'Мира', house: '8' });
    assert.equal(row.summary['trip-date'].textContent, '03.11.2026');
    assert.equal(row.summary['trip-distance'].textContent, '18 км');
    assert.equal(row.summary.destination.textContent, 'Россия, г. Мытищи, Мира, 8');

    row.inputs.date.value = '';
    row.inputs.distance.value = '';
    controller.updateTripSummary(row, {});
    assert.equal(row.summary['trip-date'].textContent, '');
    assert.equal(row.summary['trip-distance'].textContent, '');
    assert.equal(row.summary.destination.textContent, 'Новая поездка');
    assert.equal(row.classList.contains('wb-trip--has-content'), false);
});

test('insert focuses the summary of the new trip without opening it', () => {
    const row = trip({ date: '2026-11-03', distance: '18', city: 'Исходная' });
    const inserted = trip({ date: '', distance: '', city: '' });
    row.after = (fragment) => {
        assert.deepEqual(fragment, { cloned: true });
        row.nextElementSibling = inserted;
    };
    const controller = new WaybillFormController();
    controller.template = (kind) => {
        assert.equal(kind, 'trip');
        return { content: { cloneNode(deep) { assert.equal(deep, true); return { cloned: true }; } } };
    };
    let renumbered = false;
    let refreshed = false;
    controller.renumber = (kind) => {
        assert.equal(kind, 'trip');
        renumbered = true;
    };
    controller.refreshTrips = () => { refreshed = true; };
    const event = { currentTarget: { closest(selector) {
        assert.equal(selector, '[data-waybill-form-target~="tripRow"]');
        return row;
    } } };

    controller.insert(event);

    assert.equal(inserted.inputs.date.value, '2026-11-03');
    assert.equal(renumbered, true);
    assert.equal(refreshed, true);
    assert.equal(inserted.summaryElement.focused, true);
    assert.equal(inserted.details.open, false);
    assert.equal(inserted.inputs.city.focused, false);
});

test('new form without trips shows zero distance and leaves the target empty', () => {
    const controller = odometerPreview([], '42150');
    controller.initialLocationValue = {};

    controller.connect();

    assert.equal(controller.endTarget.value, '');
    assertOdometerPreview(controller, '42 150 км', '0 км', '42 150 км', 'Расхождение: —');
});

test('connect preserves the returned target and calculates from the returned trips', () => {
    const controller = odometerPreview([routeTrip('84')], '42150', '42234');
    controller.initialLocationValue = {};

    controller.connect();

    assert.equal(controller.endTarget.value, '42234');
    assertOdometerPreview(controller, '42 150 км', '84 км', '42 234 км', 'Расхождение: 0 км');

    controller.endTarget.value = '0';
    controller.recalculate();
    assert.equal(controller.endTarget.value, '0');
    assertOdometerPreview(controller, '42 150 км', '84 км', '42 234 км', 'Расхождение: -42 234 км');
});

test('changing and clearing the target updates the difference in both directions', () => {
    const controller = odometerPreview([routeTrip('84')], '100', '184');

    controller.recalculate();
    assertOdometerPreview(controller, '100 км', '84 км', '184 км', 'Расхождение: 0 км');

    controller.endTarget.value = '200';
    controller.recalculate();
    assert.equal(controller.endTarget.value, '200');
    assert.equal(controller.differenceTarget.textContent, 'Расхождение: +16 км');

    controller.endTarget.value = '170';
    controller.recalculate();
    assert.equal(controller.differenceTarget.textContent, 'Расхождение: -14 км');

    controller.endTarget.value = '';
    controller.recalculate();
    assert.equal(controller.endTarget.value, '');
    assert.equal(controller.differenceTarget.textContent, 'Расхождение: —');

    controller.endTarget.value = 'not-a-number';
    controller.recalculate();
    assert.equal(controller.differenceTarget.textContent, 'Расхождение: —');
});

test('adding, inserting, removing and editing trips preserve the target and refresh the preview', () => {
    const rows = [routeTrip('84', { city: 'Первая' })];
    const controller = odometerPreview(rows, '42150', '42234');
    controller.initialLocationValue = { country: 'Россия', city: 'База' };

    controller.refreshTrips();
    assertOdometerPreview(controller, '42 150 км', '84 км', '42 234 км', 'Расхождение: 0 км');

    rows.push(routeTrip('10', { city: 'Вторая' }));
    controller.refreshTrips();
    assert.equal(controller.endTarget.value, '42234');
    assertOdometerPreview(controller, '42 150 км', '94 км', '42 244 км', 'Расхождение: -10 км');

    rows.splice(1, 0, routeTrip('20', { city: 'Вставленная' }));
    controller.refreshTrips();
    assert.equal(controller.endTarget.value, '42234');
    assertOdometerPreview(controller, '42 150 км', '114 км', '42 264 км', 'Расхождение: -30 км');

    rows.splice(0, 1);
    controller.refreshTrips();
    assert.equal(controller.endTarget.value, '42234');
    assertOdometerPreview(controller, '42 150 км', '30 км', '42 180 км', 'Расхождение: +54 км');

    rows[0].inputs.distance.value = '25';
    controller.recalculate();
    assert.equal(controller.endTarget.value, '42234');
    assertOdometerPreview(controller, '42 150 км', '35 км', '42 185 км', 'Расхождение: +49 км');
});

test('an incomplete or invalid distance makes the whole preview neutral instead of showing a partial sum', () => {
    const rows = [routeTrip('20'), routeTrip('30')];
    const controller = odometerPreview(rows, '100', '150');
    controller.recalculate();
    assertOdometerPreview(controller, '100 км', '50 км', '150 км', 'Расхождение: 0 км');

    for (const value of ['', 'not-a-number', '0', '-1', '1.5']) {
        rows[1].inputs.distance.value = value;
        controller.recalculate();
        assert.equal(controller.endTarget.value, '150');
        assertOdometerPreview(controller, '100 км', '—', '—', 'Расхождение: —');
    }
});

test('an unfinished date or address does not prevent distance preview', () => {
    const row = routeTrip('84', { date: '', country: '', cityType: '', city: '', street: '', house: '', enterprise: '' });
    const controller = odometerPreview([row], '100', '184');

    controller.recalculate();

    assertOdometerPreview(controller, '100 км', '84 км', '184 км', 'Расхождение: 0 км');
});

test('large integers are calculated and formatted without Number rounding', () => {
    const rows = [routeTrip('9007199254740993'), routeTrip('7')];
    const controller = odometerPreview(rows, '100000000000000000000', '100009007199254741000');

    controller.recalculate();

    assert.equal(controller.endTarget.value, '100009007199254741000');
    assertOdometerPreview(controller, '100 000 000 000 000 000 000 км', '9 007 199 254 741 000 км', '100 009 007 199 254 741 000 км', 'Расхождение: 0 км');
});

test('refresh links departures, updates summaries and hides insertion after the last trip', () => {
    const controller = new WaybillFormController();
    const first = trip({ date: '2026-11-01', distance: '24', locationId: '', country: 'Россия', cityType: 'city', city: 'Химки', street: '', house: '', enterprise: '' });
    const second = trip({ date: '2026-11-02', distance: '24', locationId: '', country: 'Россия', cityType: 'city', city: 'Москва', street: '', house: '', enterprise: '' });
    controller.initialLocationValue = { id: 'base-id', country: 'Россия', city: 'База' };
    controller.tripRows = () => [first, second];
    controller.recalculate = () => {};
    controller.refreshTrips();

    assert.equal(first.departures[0].textContent, 'Россия, База');
    assert.equal(first.departures[1].textContent, 'Россия, База');
    assert.equal(second.departures[0].textContent, 'Россия, г. Химки');
    assert.equal(second.departures[1].textContent, 'Россия, г. Химки');
    assert.equal(first.summary.destination.textContent, 'Россия, г. Химки');
    assert.equal(second.summary.destination.textContent, 'Россия, г. Москва');
    assert.equal(first.insertion.hidden, false);
    assert.equal(second.insertion.hidden, true);
    controller.tripRows = () => [first];
    controller.refreshTrips();
    const added = trip({ locationId: '', country: '', cityType: '', city: '', street: '', house: '', enterprise: '', date: '', distance: '' });
    controller.copyReturnTrip(first, added);
    assert.equal(added.inputs.locationId.value, 'base-id');
});

test('manual and catalog address changes preserve the entered odometer', () => {
    const row = routeTrip('15', { city: 'Первая' });
    const controller = odometerPreview([row], '100', '760');
    controller.initialLocationValue = { id: 'base-id', country: 'Россия', city: 'База' };
    controller.recalculate();

    row.inputs.city.value = 'Ручной адрес';
    controller.refreshTripLocations();
    assert.equal(controller.endTarget.value, '760');
    assert.equal(row.summary.destination.textContent, 'Россия, г. Ручной адрес');

    row.inputs.locationId.value = 'catalog-id';
    row.inputs.city.value = 'Справочный адрес';
    controller.refreshTripLocations();
    assert.equal(controller.endTarget.value, '760');
    assert.equal(row.summary.destination.textContent, 'Россия, г. Справочный адрес');

    controller.refreshTrips();
    assert.equal(controller.endTarget.value, '760');
    assertOdometerPreview(controller, '100 км', '15 км', '115 км', 'Расхождение: +645 км');
});

test('refueling empty state follows dynamic additions and removals', () => {
    const controller = new WaybillFormController();
    const empty = { hidden: false };
    const rows = {
        hasRows: false,
        appendChild() { this.hasRows = true; },
        querySelector(selector) {
            assert.equal(selector, '[data-waybill-form-target~="refuelingRow"]');
            return this.hasRows ? {} : null;
        },
    };
    controller.element = { querySelector(selector) {
        assert.equal(selector, '[data-waybill-form-target="refuelingEmpty"]');
        return empty;
    } };
    controller.rows = (kind) => {
        assert.equal(kind, 'refueling');
        return rows;
    };
    controller.template = () => ({ content: { cloneNode: () => ({}) } });
    controller.renumber = () => {};
    controller.refreshRefuelings = () => controller.refreshCollectionState('refueling');

    controller.refreshCollectionState('refueling');
    assert.equal(empty.hidden, false);
    controller.add({ params: { kind: 'refueling' } });
    assert.equal(empty.hidden, true);

    const row = { remove() { rows.hasRows = false; } };
    controller.remove({ params: { kind: 'refueling' }, currentTarget: { closest: () => row } });
    assert.equal(empty.hidden, false);
});

test('decimal refueling total preserves fractional precision without binary rounding artifacts', () => {
    const controller = new WaybillFormController();

    assert.equal(controller.decimalTotal([]), '0');
    assert.equal(controller.decimalTotal(['0.1', '0.2']), '0,3');
    assert.equal(controller.decimalTotal(['1.20', '2.30']), '3,5');
    assert.equal(controller.decimalTotal(['1.005', '2.005', '0.00000000000000000001']), '3,01000000000000000001');
    assert.equal(controller.decimalTotal(['1', '-2']), null);
    assert.equal(controller.decimalTotal(['1', 'not-a-number']), null);
});

test('refueling summaries and total follow entered dates, fuel types and quantities', () => {
    const controller = new WaybillFormController();
    const first = trip({ date: '2026-11-03T09:14:00', fuelType: 'АИ-95', quantity: '0.1' });
    const second = trip({ date: '', fuelType: '', quantity: '0.2' });
    for (const row of [first, second]) {
        row.summary['refueling-date'] = { textContent: '' };
        row.summary['refueling-fuel-type'] = { textContent: '' };
        row.summary['refueling-quantity'] = { textContent: '' };
    }
    const total = { textContent: '' };
    controller.element = { querySelector: (selector) => selector === '[data-waybill-form-target="refueledTotal"]' ? total : null };
    controller.refuelingRows = () => [first, second];

    controller.updateRefuelingSummary(first);
    controller.updateRefuelingSummary(second);
    controller.refreshRefueledTotal();

    assert.equal(first.summary['refueling-date'].textContent, '03.11.2026');
    assert.equal(first.summary['refueling-fuel-type'].textContent, 'АИ-95');
    assert.equal(first.summary['refueling-quantity'].textContent, '0.1 л');
    assert.equal(second.summary['refueling-date'].textContent, 'Новая заправка');
    assert.equal(second.summary['refueling-fuel-type'].textContent, 'Вид топлива не выбран');
    assert.equal(total.textContent, '+0,3 л');
});

test('difference badge has ok, bad and neutral presentation', () => {
    const controller = odometerPreview([routeTrip('84')], '100', '184');
    const icon = { className: '' };
    const classes = new Set(['wb-diff--neutral']);
    controller.differenceTarget.classList = {
        remove: (...names) => names.forEach((name) => classes.delete(name)),
        add: (name) => classes.add(name),
    };
    controller.differenceTarget.querySelector = () => icon;
    controller.differenceTextTarget = { textContent: '' };

    controller.recalculate();
    assert.equal(controller.differenceTextTarget.textContent, 'Расхождение: 0 км');
    assert.equal(classes.has('wb-diff--ok'), true);
    assert.equal(icon.className, 'bi bi-check-circle-fill');

    controller.endTarget.value = '200';
    controller.recalculate();
    assert.equal(classes.has('wb-diff--bad'), true);
    assert.equal(icon.className, 'bi bi-exclamation-circle-fill');

    controller.endTarget.value = '';
    controller.recalculate();
    assert.equal(controller.differenceTextTarget.textContent, 'Расхождение: —');
    assert.equal(classes.has('wb-diff--neutral'), true);
    assert.equal(icon.className, 'bi bi-dash-circle');
});

test('trip count follows the current rows and refreshes the empty state', () => {
    const controller = new WaybillFormController();
    const count = { textContent: '' };
    let refreshedKind = null;
    controller.element = { querySelector: (selector) => selector === '[data-waybill-form-target="tripCount"]' ? count : null };
    controller.tripRows = () => [{}, {}];
    controller.refreshCollectionState = (kind) => { refreshedKind = kind; };

    controller.refreshTripCollectionState();

    assert.equal(count.textContent, '2');
    assert.equal(refreshedKind, 'trip');
});

test('renumber updates date picker ids and ARIA references together with field names', () => {
    const controller = new WaybillFormController();
    const name = { name: 'trips[__i__][date]' };
    const attributes = (values) => ({
        id: values.id || null,
        htmlFor: values.for || '',
        values,
        getAttribute(key) { return this.values[key] || null; },
        setAttribute(key, value) { this.values[key] = value; if (key === 'for') this.htmlFor = value; },
    });
    const original = attributes({ id: 'trip-date-__i__' });
    const display = attributes({ id: 'trip-date-display-__i__', 'aria-controls': 'trip-date-calendar-__i__' });
    const label = attributes({ for: 'trip-date-__i__' });
    const title = { textContent: '' };
    const row = {
        dataset: { rowLabel: 'Поездка' },
        querySelectorAll(selector) {
            if (selector === '[name]') return [name];
            if (selector === '[data-typeahead-hidden-name-value]') return [];
            if (selector === '[id], [for], [aria-controls], [aria-labelledby], [aria-describedby]') return [original, display, label];
            return [];
        },
        querySelector: (selector) => selector === '[data-role="row-title"]' ? title : null,
    };
    controller.rows = () => ({ querySelectorAll: () => [row] });

    controller.renumber('trip');

    assert.equal(name.name, 'trips[0][date]');
    assert.equal(original.id, 'trip-date-0');
    assert.equal(display.id, 'trip-date-display-0');
    assert.equal(display.values['aria-controls'], 'trip-date-calendar-0');
    assert.equal(label.htmlFor, 'trip-date-0');
    assert.equal(title.textContent, 'Поездка 1');
});

function animatedTrip({ open = false, reducedMotion = false, waapi = true } = {}) {
    const row = routeTrip('10');
    const content = {
        style: {},
        inert: !open,
        scrollHeight: 240,
        currentHeight: open ? 240 : 0,
        animations: [],
        currentAnimation: null,
        contains: () => false,
        getBoundingClientRect() { return { height: this.currentHeight }; },
    };
    if (waapi) {
        content.animate = function (keyframes, options) {
            const animation = {
                keyframes,
                options,
                cancelled: false,
                cancel() {
                    this.cancelled = true;
                    this.oncancel?.();
                },
                finish: () => {
                    content.currentHeight = Number.parseFloat(keyframes.at(-1).height);
                    animation.onfinish?.();
                },
            };
            this.animations.push(animation);
            this.currentAnimation = animation;
            return animation;
        };
    }
    const details = {
        open,
        isConnected: true,
        ownerDocument: { activeElement: null, defaultView: { matchMedia: () => ({ matches: reducedMotion }) } },
        querySelector(selector) {
            if (selector === '.waybill-trip-content') return content;
            if (selector === 'summary') return row.summaryElement;
            return null;
        },
    };
    row.details = details;
    row.content = content;
    row.remove = () => { details.isConnected = false; };
    return row;
}

function accordion(rows) {
    const controller = new WaybillFormController();
    controller.tripStates = new WeakMap();
    controller.tripRows = () => rows;
    rows.forEach((row) => controller.prepareTrip(row.details));
    return controller;
}

function animationHeights(animation) {
    return Array.from(animation.keyframes, (frame) => frame.height);
}

test('trip opening and closing animate through real intermediate content heights', async () => {
    const row = animatedTrip();
    const controller = accordion([row]);
    const opening = controller.requestTrip(row.details, true);

    assert.equal(row.details.open, true);
    assert.equal(row.content.inert, false);
    assert.deepEqual(animationHeights(row.content.currentAnimation), ['0px', '240px']);
    assert.equal(row.content.currentAnimation.options.duration, 200);
    row.content.currentHeight = 117;
    assert.equal(row.content.getBoundingClientRect().height, 117);
    row.content.currentAnimation.finish();
    await opening;
    assert.equal(row.details.open, true);
    assert.equal(row.content.style.height, '');
    assert.equal(row.content.style.overflow, '');

    const closing = controller.requestTrip(row.details, false);
    assert.equal(row.details.open, true);
    assert.equal(row.content.inert, true);
    assert.deepEqual(animationHeights(row.content.currentAnimation), ['240px', '0px']);
    row.content.currentHeight = 83;
    assert.equal(row.content.getBoundingClientRect().height, 83);
    row.content.currentAnimation.finish();
    await closing;
    assert.equal(row.details.open, false);
    assert.equal(row.content.inert, true);
    assert.equal(row.content.style.height, '');
});

test('switching trips closes the old card before the new one starts opening', async () => {
    const first = animatedTrip({ open: true });
    const second = animatedTrip();
    const controller = accordion([first, second]);
    const switching = controller.requestTrip(second.details, true);

    assert.equal(first.details.open, true);
    assert.equal(second.details.open, false);
    assert.deepEqual(animationHeights(first.content.currentAnimation), ['240px', '0px']);
    assert.equal(second.content.animations.length, 0);
    first.content.currentAnimation.finish();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(first.details.open, false);
    assert.equal(second.details.open, true);
    assert.deepEqual(animationHeights(second.content.currentAnimation), ['0px', '240px']);
    second.content.currentAnimation.finish();
    await switching;
    assert.equal(first.details.open, false);
    assert.equal(second.details.open, true);
    assert.equal(second.content.style.height, '');
});

test('rapid reversal cancels the previous animation and continues from its current height', async () => {
    const row = animatedTrip();
    const controller = accordion([row]);
    const opening = controller.requestTrip(row.details, true);
    row.content.currentHeight = 70;

    const closing = controller.requestTrip(row.details, false);
    assert.equal(row.content.animations[0].cancelled, true);
    assert.deepEqual(animationHeights(row.content.currentAnimation), ['70px', '0px']);
    row.content.currentHeight = 40;
    const reopening = controller.requestTrip(row.details, true);
    assert.equal(row.content.animations[1].cancelled, true);
    assert.deepEqual(animationHeights(row.content.currentAnimation), ['40px', '240px']);
    assert.equal(row.details.open, true);

    row.content.currentAnimation.finish();
    await Promise.all([opening, closing, reopening]);
    assert.equal(row.details.open, true);
    assert.equal(row.content.inert, false);
    assert.equal(row.content.style.height, '');
});

test('reduced motion and missing Web Animations API settle instantly', async () => {
    for (const row of [animatedTrip({ reducedMotion: true }), animatedTrip({ waapi: false })]) {
        const controller = accordion([row]);
        await controller.requestTrip(row.details, true);
        assert.equal(row.details.open, true);
        assert.equal(row.content.animations.length, 0);
        assert.equal(row.content.inert, false);
        await controller.requestTrip(row.details, false);
        assert.equal(row.details.open, false);
        assert.equal(row.content.inert, true);
        assert.equal(row.content.style.height, '');
    }
});

test('removing an animated trip and disconnecting leave no fixed height or stale opening request', async () => {
    const first = animatedTrip({ open: true });
    const second = animatedTrip();
    const rows = [first, second];
    const controller = accordion(rows);
    controller.renumber = () => {};
    controller.refreshTrips = () => {};
    const switching = controller.requestTrip(second.details, true);
    controller.remove({ params: { kind: 'trip' }, currentTarget: { closest: () => second } });
    first.content.currentAnimation?.finish();
    await switching;

    assert.equal(second.details.isConnected, false);
    assert.equal(second.details.open, false);
    assert.equal(second.content.style.height, '');
    assert.equal(first.details.open, false);

    const third = animatedTrip();
    rows.push(third);
    controller.prepareTrip(third.details);
    controller.requestTrip(third.details, true);
    third.content.currentHeight = 90;
    controller.disconnect();
    assert.equal(third.details.open, true);
    assert.equal(third.content.inert, false);
    assert.equal(third.content.style.height, '');
    assert.equal(third.content.style.overflow, '');
    assert.equal(third.content.currentAnimation.cancelled, true);
});
