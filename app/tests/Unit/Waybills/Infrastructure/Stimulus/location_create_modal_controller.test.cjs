const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const modal = { shown: 0, hidden: 0, show() { ++this.shown; }, hide() { ++this.hidden; } };
const window = { bootstrap: { Modal: { getOrCreateInstance: () => modal } } };
const document = { getElementById: () => ({}) };
const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/location_create_modal_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class LocationCreateModalController extends Controller');
const LocationCreateModalController = vm.runInNewContext(`${source}\nLocationCreateModalController;`, { Event, window, document, setTimeout });

function input() {
    return { value: '', events: [], focused: false, dispatchEvent(event) { this.events.push(event.type); }, focus() { this.focused = true; } };
}

function modalController() {
    const controller = new LocationCreateModalController();
    for (const field of ['country', 'city', 'cityType', 'street', 'house', 'enterprise', 'searchInput']) controller[`${field}Target`] = input();
    controller.errorBoxTarget = { textContent: '', classList: { add() {}, remove() {} } };
    controller.titleTarget = { textContent: '' };
    controller.searchPanelTarget = { hidden: true };
    controller.formPanelTarget = { hidden: false };
    controller.createButtonTarget = { hidden: true };
    controller.backButtonTarget = { hidden: true };
    controller.submitButtonTarget = { hidden: false };
    return controller;
}

test('picker opens the standard modal with search and create actions', () => {
    const controller = modalController();
    const requester = { createUrlValue: '/quick' };

    controller.openPicker(requester);

    assert.equal(controller.titleTarget.textContent, 'Найти или создать точку');
    assert.equal(controller.searchPanelTarget.hidden, false);
    assert.equal(controller.formPanelTarget.hidden, true);
    assert.equal(controller.createButtonTarget.hidden, false);
    assert.equal(controller.submitButtonTarget.hidden, true);
    assert.deepEqual(controller.searchInputTarget.events, ['input']);
    assert.equal(modal.shown, 1);

    controller.searchInputTarget.value = 'Москва';
    controller.showCreate();
    assert.equal(controller.cityTarget.value, 'Москва');
    assert.equal(controller.searchPanelTarget.hidden, true);
    assert.equal(controller.formPanelTarget.hidden, false);
    assert.equal(controller.backButtonTarget.hidden, false);
    assert.equal(controller.submitButtonTarget.hidden, false);

    controller.streetTarget.value = 'Лесная';
    controller.enterpriseTarget.value = 'Склад';
    controller.showSearch();
    assert.equal(controller.searchPanelTarget.hidden, false);
    assert.equal(controller.formPanelTarget.hidden, true);
    controller.searchInputTarget.value = 'Другой запрос';
    controller.showCreate();
    assert.equal(controller.cityTarget.value, 'Москва');
    assert.equal(controller.streetTarget.value, 'Лесная');
    assert.equal(controller.enterpriseTarget.value, 'Склад');

    controller.openPicker(requester);
    assert.equal(controller.cityTarget.value, '');
    assert.equal(controller.streetTarget.value, '');
    assert.equal(controller.enterpriseTarget.value, '');
    assert.deepEqual(controller.searchInputTarget.events, ['input', 'input']);
    controller.searchInputTarget.value = 'Новый поиск';
    controller.showCreate();
    assert.equal(controller.cityTarget.value, 'Новый поиск');
});

test('selecting a found location returns it to the trip requester and closes the modal', () => {
    const controller = modalController();
    const location = { id: 'location-id', title: 'Москва' };
    let received = null;
    controller.openPicker({ addCreated(value) { received = value; } });
    const before = modal.hidden;

    controller.selectLocation({ detail: location });

    assert.equal(received, location);
    assert.equal(modal.hidden, before + 1);
});

test('the existing create-only entry point remains available to other location forms', () => {
    const controller = modalController();

    controller.open('Мытищи', { createUrlValue: '/quick' });

    assert.equal(controller.cityTarget.value, 'Мытищи');
    assert.equal(controller.searchPanelTarget.hidden, true);
    assert.equal(controller.formPanelTarget.hidden, false);
    assert.equal(controller.backButtonTarget.hidden, true);
    assert.equal(controller.submitButtonTarget.hidden, false);
});
