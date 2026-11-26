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
    const inputs = Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, { value }]));
    const summary = Object.fromEntries(['trip-date', 'trip-distance', 'destination'].map((key) => [key, { textContent: '' }]));
    const departures = [{ textContent: '' }, { textContent: '' }];
    const insertion = { hidden: false };
    const routeLine = { hidden: false };
    return {
        inputs, summary, departures, insertion, routeLine,
        querySelector(selector) {
            const field = selector.match(/^\[name\$="\[(.+)\]"\]$/)?.[1];
            if (field) return inputs[field];
            const role = selector.match(/^\[data-role="(.+)"\]$/)?.[1];
            if (role === 'trip-insertion') return insertion;
            if (role === 'route-line') return routeLine;
            return summary[role] ?? null;
        },
        querySelectorAll(selector) { return selector === '[data-role="departure"]' ? departures : []; },
    };
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

test('connect preserves the returned odometer and later changes recalculate it', () => {
    const controller = new WaybillFormController();
    controller.startValue = '100';
    controller.initialLocationValue = {};
    controller.endTarget = { value: '120' };
    controller.tripRows = () => [];

    controller.connect();
    assert.equal(controller.endTarget.value, '120');

    controller.refreshTrips();
    assert.equal(controller.endTarget.value, '100');
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
    assert.equal(first.routeLine.hidden, false);
    assert.equal(second.routeLine.hidden, true);
    controller.tripRows = () => [first];
    controller.refreshTrips();
    assert.equal(first.routeLine.hidden, true);
    const added = trip({ locationId: '', country: '', cityType: '', city: '', street: '', house: '', enterprise: '', date: '', distance: '' });
    controller.copyReturnTrip(first, added);
    assert.equal(added.inputs.locationId.value, 'base-id');
});

test('manual and catalog address changes preserve the entered odometer', () => {
    const controller = new WaybillFormController();
    const row = trip({ date: '2026-11-01', distance: '15', locationId: '', country: 'Россия', cityType: 'city', city: 'Первая', street: '', house: '', enterprise: '' });
    controller.startValue = '100';
    controller.initialLocationValue = { id: 'base-id', country: 'Россия', city: 'База' };
    controller.endTarget = { value: '760' };
    controller.tripRows = () => [row];

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
    assert.equal(controller.endTarget.value, '115');
});
