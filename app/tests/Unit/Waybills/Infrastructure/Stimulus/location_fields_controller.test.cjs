const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/location_fields_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class LocationFieldsController extends Controller');
const LocationFieldsController = vm.runInNewContext(`${source}\nLocationFieldsController;`, { Event });
const fields = ['country', 'city', 'cityType', 'street', 'house', 'enterprise'];

function input(value = '') {
    return { value, events: [], dispatchEvent(event) { this.events.push(event.type); } };
}

function locationFields(values = {}, card = true) {
    const controller = new LocationFieldsController();
    controller.cardValue = card;
    for (const field of [...fields, 'locationId']) {
        controller[`${field}Target`] = input(values[field] || '');
        controller[`has${field.charAt(0).toUpperCase() + field.slice(1)}Target`] = true;
    }
    controller.searchInputTarget = input(values.title || '');
    controller.hasSearchInputTarget = true;
    controller.clearButtonTarget = { hidden: false };
    controller.hasClearButtonTarget = true;
    return controller;
}

test('connect only updates clear visibility without changing location values', () => {
    const values = { locationId: 'location-id', country: 'Россия', city: 'Москва', cityType: 'city', street: 'Лесная', house: '1', enterprise: 'Склад' };
    const controller = locationFields(values);

    controller.connect();

    assert.equal(controller.clearButtonTarget.hidden, false);
    assert.equal(controller.toggleManual, undefined);
    assert.equal(LocationFieldsController.targets.includes('manualButton'), false);
    assert.equal(LocationFieldsController.targets.includes('fieldsPanel'), false);
    for (const [field, value] of Object.entries(values)) {
        assert.equal(controller[`${field}Target`].value, value);
        assert.deepEqual(controller[`${field}Target`].events, []);
    }
});

test('an empty location hides clear after connect', () => {
    const controller = locationFields();

    controller.connect();

    assert.equal(controller.hasLocation(), false);
    assert.equal(controller.clearButtonTarget.hidden, true);
});

test('country alone is a location and enables clear', () => {
    const controller = locationFields({ country: 'Россия' });

    controller.connect();

    assert.equal(controller.hasLocation(), true);
    assert.equal(controller.clearButtonTarget.hidden, false);

    controller.countryTarget.value = '  ';
    assert.equal(controller.hasLocation(), false);
    controller.locationIdTarget.value = 'selected-id';
    assert.equal(controller.hasLocation(), true);
});

test('a selected or created location replaces all destination fields and enables clear', () => {
    const controller = locationFields();
    controller.connect();

    controller.addCreated({ id: 'selected-id', country: 'Россия', city: 'Мытищи', cityType: 'city', street: 'Мира', house: '8', enterprise: 'Склад' });

    assert.equal(controller.locationIdTarget.value, 'selected-id');
    assert.equal(controller.countryTarget.value, 'Россия');
    assert.equal(controller.cityTarget.value, 'Мытищи');
    assert.equal(controller.cityTypeTarget.value, 'city');
    assert.equal(controller.streetTarget.value, 'Мира');
    assert.equal(controller.houseTarget.value, '8');
    assert.equal(controller.enterpriseTarget.value, 'Склад');
    assert.deepEqual(controller.locationIdTarget.events, ['change']);
    assert.equal(controller.cityTarget.events.at(-1), 'input');
    assert.equal(controller.clearButtonTarget.hidden, false);
});

test('clear removes the complete destination and the visible typeahead value', () => {
    const controller = locationFields({ locationId: 'location-id', country: 'Россия', city: 'Москва', title: 'Россия, Москва' });
    controller.searchInputTarget.value = 'Россия, Москва';
    controller.connect();

    controller.clear();

    for (const field of [...fields, 'locationId']) assert.equal(controller[`${field}Target`].value, '');
    assert.equal(controller.searchInputTarget.value, '');
    assert.deepEqual(controller.locationIdTarget.events, ['input', 'change']);
    assert.equal(controller.clearButtonTarget.hidden, true);

    controller.addCreated({ id: 'country-only-id', country: 'Россия' });
    assert.equal(controller.locationIdTarget.value, 'country-only-id');
    assert.equal(controller.hasLocation(), true);
    assert.equal(controller.clearButtonTarget.hidden, false);
});

test('connect leaves ordinary editable location forms unchanged', () => {
    const controller = locationFields({ country: 'Россия', city: 'Москва' }, false);

    controller.connect();

    assert.equal(controller.countryTarget.value, 'Россия');
    assert.equal(controller.cityTarget.value, 'Москва');
    assert.deepEqual(controller.countryTarget.events, []);

    controller.select({ detail: { country: 'Россия', city: 'Мытищи', cityType: 'city' } });
    assert.equal(controller.cityTarget.value, 'Мытищи');
    assert.equal(controller.cityTypeTarget.value, 'city');
    assert.deepEqual(controller.cityTarget.events, ['input']);
});
