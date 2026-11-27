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
        classList: { add() {}, remove() {} },
        value: '',
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return this.attributes[name] ?? null; },
        addEventListener() {},
        dispatchEvent() {},
        remove() {},
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
const window = {
    location: { origin: 'http://localhost' },
    addEventListener() {},
    removeEventListener() {},
};
let fetchImplementation = async () => { throw new Error('Unexpected fetch'); };
const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/typeahead_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class TypeaheadController extends Controller');
const TypeaheadController = vm.runInNewContext(`${source}\nTypeaheadController;`, {
    document, window, URL, Event, setTimeout, clearTimeout,
    fetch: (...args) => fetchImplementation(...args),
});

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
    controller.endpointValue = '/suggest';
    controller.mainFieldValue = 'title';
    controller.auxFieldsValue = [];
    controller.allowCreateValue = false;
    controller.minLengthValue = 3;
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

function deferred() {
    let resolve;
    const promise = new Promise((res) => { resolve = res; });
    return { promise, resolve };
}

function searchResponse(title) {
    return {
        ok: true,
        async json() { return { items: [{ id: title, title }], hasMore: false }; },
    };
}

async function assertStaleResponseIsIgnored(invalidate) {
    const controller = connectedTypeahead('locationId', '', '');
    const pending = deferred();
    const rendered = [];
    controller._loadingState = () => {};
    controller._render = (items, query) => rendered.push({ items, query });
    fetchImplementation = () => pending.promise;
    const request = controller._fetch('Старый', 1, false);

    invalidate(controller);
    pending.resolve(searchResponse('Старый'));
    await request;

    assert.deepEqual(rendered, []);
    controller.disconnect();
}

test('editing the query invalidates a previous request before the debounce fires', async () => {
    await assertStaleResponseIsIgnored((controller) => {
        controller.element.value = 'Новый запрос';
        controller._onInput();
        clearTimeout(controller._debounce);
        controller._debounce = null;
    });
});

test('clearing the query invalidates a previous request and closes the results', async () => {
    await assertStaleResponseIsIgnored((controller) => {
        controller.element.value = '';
        controller._onInput();
    });
});

test('closing the picker invalidates a previous request and cancels a pending search', async () => {
    await assertStaleResponseIsIgnored((controller) => {
        controller.element.value = 'Отложенный';
        controller._onInput();
        controller._hide();
        assert.equal(controller._debounce, null);
    });
});

test('only the latest completed query renders its results', async () => {
    const controller = connectedTypeahead('locationId', '', '');
    const first = deferred();
    const second = deferred();
    const rendered = [];
    controller._show = () => {};
    controller._render = (items, query) => rendered.push({ items, query });
    fetchImplementation = (url) => 'Первый' === url.searchParams.get('q') ? first.promise : second.promise;
    const firstRequest = controller._fetch('Первый', 1, false);
    const secondRequest = controller._fetch('Второй', 1, false);

    second.resolve(searchResponse('Второй'));
    await secondRequest;
    first.resolve(searchResponse('Первый'));
    await firstRequest;

    assert.equal(rendered.length, 1);
    assert.equal(rendered[0].query, 'Второй');
    assert.equal(rendered[0].items[0].title, 'Второй');
    controller.disconnect();
});
