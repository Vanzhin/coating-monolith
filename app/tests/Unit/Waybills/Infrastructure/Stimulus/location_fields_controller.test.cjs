const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const dom = { modalElement: {} };
const document = { querySelector: (selector) => selector === '[data-controller~="location-create-modal"]' ? dom.modalElement : null };
const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/location_fields_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class LocationFieldsController extends Controller');
const LocationFieldsController = vm.runInNewContext(`${source}\nLocationFieldsController;`, { Event, document });
const fields = ['country', 'city', 'cityType', 'street', 'house', 'enterprise'];

function input(value = '') {
    return { value, events: [], dispatchEvent(event) { this.events.push(event.type); } };
}

function locationFields(values = {}, card = true) {
    const controller = new LocationFieldsController();
    controller.cardValue = card;
    controller.createUrlValue = '/cabinet/waybill/locations/quick';
    for (const field of [...fields, 'locationId']) {
        controller[`${field}Target`] = input(values[field] || '');
        controller[`has${field.charAt(0).toUpperCase() + field.slice(1)}Target`] = true;
    }
    controller.summaryTextTarget = { textContent: '' };
    controller.hasSummaryTextTarget = true;
    controller.findButtonTarget = { hidden: false };
    controller.clearButtonTarget = { hidden: false };
    controller.cardPanelTarget = { hidden: true };
    controller.hasCardPanelTarget = true;
    controller.fieldsPanelTarget = { hidden: false };
    controller.hasFieldsPanelTarget = true;
    return controller;
}

test('connect replaces the visible fallback fields with a filled location card', () => {
    const values = { locationId: 'location-id', country: 'Россия', city: 'Москва', cityType: 'city', street: 'Лесная', house: '1', enterprise: 'Склад' };
    const controller = locationFields(values);

    assert.equal(controller.fieldsPanelTarget.hidden, false);
    assert.equal(controller.cardPanelTarget.hidden, true);

    let fieldsHidden = false;
    Object.defineProperty(controller.fieldsPanelTarget, 'hidden', {
        get: () => fieldsHidden,
        set(value) {
            if (value) assert.equal(controller.summaryTextTarget.textContent, 'Россия, Москва, Лесная, 1, Склад');
            fieldsHidden = value;
        },
    });
    let cardHidden = true;
    Object.defineProperty(controller.cardPanelTarget, 'hidden', {
        get: () => cardHidden,
        set(value) {
            if (!value) assert.equal(controller.fieldsPanelTarget.hidden, true);
            cardHidden = value;
        },
    });
    controller.connect();

    assert.equal(controller.fieldsPanelTarget.hidden, true);
    assert.equal(controller.cardPanelTarget.hidden, false);
    assert.equal(controller.summaryTextTarget.textContent, 'Россия, Москва, Лесная, 1, Склад');
    assert.equal(controller.findButtonTarget.hidden, true);
    assert.equal(controller.clearButtonTarget.hidden, false);
    for (const [field, value] of Object.entries(values)) {
        assert.equal(controller[`${field}Target`].value, value);
        assert.deepEqual(controller[`${field}Target`].events, []);
    }
});

test('an empty location shows find and hides delete after connect', () => {
    const controller = locationFields();

    controller.connect();

    assert.equal(controller.hasLocation(), false);
    assert.equal(controller.fieldsPanelTarget.hidden, true);
    assert.equal(controller.cardPanelTarget.hidden, false);
    assert.equal(controller.summaryTextTarget.textContent, 'Не указана');
    assert.equal(controller.findButtonTarget.hidden, false);
    assert.equal(controller.clearButtonTarget.hidden, true);
});

test('country alone is a location, independently of its id and without initialFilled', () => {
    assert.equal(Object.hasOwn(LocationFieldsController.values, 'initialFilled'), false);
    const controller = locationFields({ country: 'Россия' });

    controller.connect();

    assert.equal(controller.hasLocation(), true);
    assert.equal(controller.summaryTextTarget.textContent, 'Россия');
    assert.equal(controller.findButtonTarget.hidden, true);
    assert.equal(controller.clearButtonTarget.hidden, false);

    controller.countryTarget.value = '  ';
    assert.equal(controller.hasLocation(), false);
    controller.locationIdTarget.value = 'selected-id';
    assert.equal(controller.hasLocation(), true);
});

test('connect keeps manual fields editable when only auxiliary location data was returned after an error', () => {
    const cases = [
        { enterprise: 'Склад' },
        { cityType: 'city' },
        { locationId: 'location-id' },
        { locationId: 'location-id', cityType: 'city', enterprise: 'Склад', country: '  ' },
    ];

    for (const values of cases) {
        const controller = locationFields(values);

        controller.connect();

        assert.equal(controller.fieldsPanelTarget.hidden, false);
        assert.equal(controller.cardPanelTarget.hidden, true);
        for (const [field, value] of Object.entries(values)) {
            assert.equal(controller[`${field}Target`].value, value);
            assert.deepEqual(controller[`${field}Target`].events, []);
        }
    }
});

test('a location selected or created in the modal replaces all destination fields', () => {
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
    assert.equal(controller.summaryTextTarget.textContent, 'Россия, Мытищи, Мира, 8, Склад');
    assert.equal(controller.findButtonTarget.hidden, true);
    assert.equal(controller.clearButtonTarget.hidden, false);
});

test('clear removes the complete destination and exposes find again', () => {
    const controller = locationFields({ locationId: 'location-id', country: 'Россия', city: 'Москва', cityType: 'city', street: 'Лесная', house: '1', enterprise: 'Склад' });
    controller.connect();

    controller.clear();

    for (const field of [...fields, 'locationId']) assert.equal(controller[`${field}Target`].value, '');
    assert.deepEqual(controller.locationIdTarget.events, ['input', 'change']);
    assert.equal(controller.summaryTextTarget.textContent, 'Не указана');
    assert.equal(controller.findButtonTarget.hidden, false);
    assert.equal(controller.clearButtonTarget.hidden, true);

    controller.addCreated({ id: 'country-only-id', country: 'Россия' });
    assert.equal(controller.locationIdTarget.value, 'country-only-id');
    assert.equal(controller.hasLocation(), true);
    assert.equal(controller.summaryTextTarget.textContent, 'Россия');
    assert.equal(controller.findButtonTarget.hidden, true);
    assert.equal(controller.clearButtonTarget.hidden, false);
});

test('connect leaves ordinary editable location forms unchanged', () => {
    const controller = locationFields({ country: 'Россия', city: 'Москва' }, false);
    delete controller.fieldsPanelTarget;
    delete controller.cardPanelTarget;
    controller.hasFieldsPanelTarget = false;
    controller.hasCardPanelTarget = false;

    controller.connect();

    assert.equal(controller.countryTarget.value, 'Россия');
    assert.equal(controller.cityTarget.value, 'Москва');
    assert.deepEqual(controller.countryTarget.events, []);

    controller.select({ detail: { country: 'Россия', city: 'Мытищи', cityType: 'city' } });
    assert.equal(controller.cityTarget.value, 'Мытищи');
    assert.equal(controller.cityTypeTarget.value, 'city');
    assert.deepEqual(controller.cityTarget.events, ['input']);
});

test('find opens the standard application modal in picker mode', () => {
    const controller = locationFields();
    let openedBy = null;
    let requestedElement = null;
    controller.application = {
        getControllerForElementAndIdentifier(element, identifier) {
            requestedElement = { element, identifier };
            return { openPicker(requester) { openedBy = requester; } };
        },
    };

    controller.find();

    assert.equal(openedBy, controller);
    assert.deepEqual(requestedElement, { element: dom.modalElement, identifier: 'location-create-modal' });
});
