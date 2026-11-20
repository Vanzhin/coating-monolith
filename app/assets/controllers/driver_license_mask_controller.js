import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    beforeInput(event) {
        const input = this.element;
        const position = input.selectionStart;
        if (position !== input.selectionEnd) return;

        const backward = event.inputType === 'deleteContentBackward';
        const forward = event.inputType === 'deleteContentForward';
        const separatorPosition = backward ? position - 1 : position;
        if ((!backward && !forward) || input.value[separatorPosition] !== ' ') return;

        const characterPosition = backward ? separatorPosition - 1 : separatorPosition + 1;
        if (!/[0-9a-zа-яё]/i.test(input.value[characterPosition] ?? '')) return;

        event.preventDefault();
        input.setRangeText('', characterPosition, characterPosition + 1, 'start');
        this.format();
    }

    format() {
        const input = this.element;
        const position = input.selectionStart;
        const charsBeforeCursor = input.value.slice(0, position).replace(/[^0-9a-zа-яё]/gi, '').length;
        const chars = input.value.replace(/[^0-9a-zа-яё]/gi, '').slice(0, 10);
        const groups = [chars.slice(0, 2), chars.slice(2, 4), chars.slice(4, 10)];
        input.value = groups[0] + (groups[1] ? ' ' + groups[1] : '') + (groups[2] ? ' ' + groups[2] : '');

        let cursor = 0;
        let seen = 0;
        while (cursor < input.value.length && seen < charsBeforeCursor) {
            if (input.value[cursor] !== ' ') seen++;
            cursor++;
        }
        input.setSelectionRange(cursor, cursor);
    }
}
