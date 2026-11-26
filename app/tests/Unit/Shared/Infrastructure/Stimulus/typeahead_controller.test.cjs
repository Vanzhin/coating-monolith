const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element(tagName) {
    return {
        tagName: tagName.toUpperCase(),
        children: [],
        parentElement: null,
        attributes: {},
        classList: { add() {} },
        value: '',
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return this.attributes[name] ?? null; },
        addEventListener() {},
        appendChild(child) {
            child.parentElement = this;
            this.children.push(child);
            return child;
        },
        insertAdjacentElement(position, sibling) {
            const index = this.parentElement.children.indexOf(this);
            sibling.parentElement = this.parentElement;
            this.parentElement.children.splice(index + 1, 0, sibling);
            return sibling;
        },
        querySelectorAll(selector) {
            if (selector !== 'input[type="hidden"]') return [];
            return this.children.filter((child) => child.tagName === 'INPUT' && child.type === 'hidden');
        },
    };
}

const document = {
    body: element('body'),
    createElement: element,
    addEventListener() {},
    removeEventListener() {},
};
const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/typeahead_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class TypeaheadController extends Controller');
const TypeaheadController = vm.runInNewContext(`${source}\nTypeaheadController;`, { document, setTimeout, clearTimeout });

function connectedTypeahead(hiddenName, id, title) {
    const parent = element('div');
    const search = parent.appendChild(element('input'));
    const hidden = parent.appendChild(element('input'));
    hidden.type = 'hidden';
    hidden.name = hiddenName;
    const controller = new TypeaheadController();
    controller.element = search;
    controller.hiddenNameValue = hiddenName;
    controller.existingValue = { id, title };
    controller.parentFieldValue = '';
    controller.connect();

    return controller;
}

test('nested location title keeps the UUID intact in real PHP POST parsing', () => {
    const id = '018f2f1c-0e7a-7d4a-9d2a-9a7b3d9c8b10';
    const title = 'Россия, г. Москва, Лесная, 1';
    const controller = connectedTypeahead('trips[0][locationId]', id, title);

    assert.equal(controller._hiddenTitle.name, 'trips[0][locationTitle]');
    const body = new URLSearchParams();
    body.append(controller._hidden.name, controller._hidden.value);
    body.append(controller._hiddenTitle.name, controller._hiddenTitle.value);
    const parsed = JSON.parse(execFileSync('php', [
        '-r',
        'parse_str($argv[1], $input); echo json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);',
        body.toString(),
    ], { encoding: 'utf8' }));

    assert.equal(parsed.trips[0].locationId, id);
    assert.equal(parsed.trips[0].locationTitle, title);
    assert.equal(parsed.trips[0].locationIdTitle, undefined);
});

test('flat and list companion names keep their established PHP-safe shape', () => {
    assert.equal(connectedTypeahead('organizationId', 'id', 'title')._hiddenTitle.name, 'organizationTitle');
    assert.equal(connectedTypeahead('positionIds[]', 'id', 'title')._hiddenTitle.name, 'positionIdsTitle[]');
});
