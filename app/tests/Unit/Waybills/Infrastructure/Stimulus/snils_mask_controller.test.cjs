const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/snils_mask_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace('export default class extends Controller', 'class SnilsMaskController extends Controller');
const SnilsMaskController = vm.runInNewContext(`${source}\nSnilsMaskController;`);

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
    const instance = new SnilsMaskController();
    instance.element = input;
    return instance;
}

test('formats typing and pasted numbers, limiting to eleven digits', () => {
    const input = createInput();
    const mask = controller(input);
    for (const digit of '11223344595') {
        const position = input.selectionStart;
        input.value = input.value.slice(0, position) + digit + input.value.slice(position);
        input.setSelectionRange(position + 1, position + 1);
        mask.format();
    }
    assert.equal(input.value, '112-233-445 95');
    assert.equal(input.selectionStart, 14);

    input.value = '112-233-445 95123';
    input.setSelectionRange(input.value.length, input.value.length);
    mask.format();
    assert.equal(input.value, '112-233-445 95');
    assert.equal(input.selectionStart, 14);
});

test('keeps cursor position when inserting in the middle', () => {
    const input = createInput('112-2933-445 95', 6);
    controller(input).format();
    assert.equal(input.value, '112-293-344 59');
    assert.equal(input.selectionStart, 6);
});

test('deletes adjacent digits when backspace or delete meets a separator', () => {
    for (const [inputType, position, expected, cursor] of [
        ['deleteContentBackward', 4, '112-334-459 5', 2],
        ['deleteContentForward', 3, '112-334-459 5', 3],
    ]) {
        const input = createInput('112-233-445 95', position);
        let prevented = false;
        controller(input).beforeInput({ inputType, preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(input.value, expected);
        assert.equal(input.selectionStart, cursor);
    }
});
