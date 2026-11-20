const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/driver_license_mask_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class DriverLicenseMaskController extends Controller');
const DriverLicenseMaskController = vm.runInNewContext(`${source}\nDriverLicenseMaskController;`);

function createInput(value = '', position = value.length) {
    return {
        value,
        selectionStart: position,
        selectionEnd: position,
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
        setRangeText(text, start, end) {
            this.value = this.value.slice(0, start) + text + this.value.slice(end);
            this.setSelectionRange(start, start);
        },
    };
}

function controller(input) {
    const instance = new DriverLicenseMaskController();
    instance.element = input;
    return instance;
}

test('formats typing numeric and Cyrillic series', () => {
    for (const [value, expected] of [['9912123456', '99 12 123456'], ['77аа123456', '77 аа 123456']]) {
        const input = createInput();
        const mask = controller(input);
        for (const character of value) {
            const position = input.selectionStart;
            input.value = input.value.slice(0, position) + character + input.value.slice(position);
            input.setSelectionRange(position + 1, position + 1);
            mask.format();
        }
        assert.equal(input.value, expected);
        assert.equal(input.selectionStart, 12);
    }
});

test('formats pasted values and keeps the cursor when editing', () => {
    const input = createInput('77-Аa-123456789');
    const mask = controller(input);
    mask.format();
    assert.equal(input.value, '77 Аa 123456');
    assert.equal(input.selectionStart, 12);

    input.value = '77 АБа 123456';
    input.setSelectionRange(5, 5);
    mask.format();
    assert.equal(input.value, '77 АБ а12345');
    assert.equal(input.selectionStart, 5);
});

test('backspace and delete skip separators and remove adjacent characters', () => {
    for (const [inputType, position, expected, cursor] of [
        ['deleteContentBackward', 3, '7А А1 23456', 1],
        ['deleteContentForward', 2, '77 А1 23456', 2],
        ['deleteContentBackward', 6, '77 А1 23456', 4],
        ['deleteContentForward', 5, '77 АА 23456', 5],
    ]) {
        const input = createInput('77 АА 123456', position);
        let prevented = false;
        controller(input).beforeInput({ inputType, preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(input.value, expected);
        assert.equal(input.selectionStart, cursor);
    }
});

test('native deletion handles selections and ordinary characters', () => {
    const input = createInput('77 АА 123456', 2);
    input.selectionEnd = 4;
    let prevented = false;
    const mask = controller(input);
    mask.beforeInput({ inputType: 'deleteContentForward', preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    input.selectionEnd = 2;
    mask.beforeInput({ inputType: 'deleteContentBackward', preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
});
