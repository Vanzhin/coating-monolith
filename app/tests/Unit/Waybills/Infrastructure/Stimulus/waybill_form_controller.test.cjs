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
    return {
        inputs, summary, summaryElement, details, departures, insertion,
        querySelector(selector) {
            if (selector === 'summary') return summaryElement;
            if (selector === 'details') return details;
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
    assert.equal(row.summary['trip-date'].textContent, 'Дата не указана');
    assert.equal(row.summary['trip-distance'].textContent, 'Расстояние не указано');
    assert.equal(row.summary.destination.textContent, 'Не указана');
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
