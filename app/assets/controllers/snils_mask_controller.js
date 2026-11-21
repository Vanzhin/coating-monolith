import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    beforeInput(event) {
        const input = this.element;
        const position = input.selectionStart;
        if (position !== input.selectionEnd) return;

        const backward = event.inputType === 'deleteContentBackward';
        const forward = event.inputType === 'deleteContentForward';
        const separatorPosition = backward ? position - 1 : position;
        if ((!backward && !forward) || !/[- ]/.test(input.value[separatorPosition] ?? '')) return;

        const digitPosition = backward ? separatorPosition - 1 : separatorPosition + 1;
        if (!/\d/.test(input.value[digitPosition] ?? '')) return;

        event.preventDefault();
        input.setRangeText('', digitPosition, digitPosition + 1, 'start');
        this.format();
    }

    format() {
        const input = this.element;
        const position = input.selectionStart;
        const digitsBeforeCursor = input.value.slice(0, position).replace(/\D/g, '').length;
        const digits = input.value.replace(/\D/g, '').slice(0, 11);
        const groups = [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 9), digits.slice(9, 11)];
        input.value = groups[0] + (groups[1] ? '-' + groups[1] : '')
            + (groups[2] ? '-' + groups[2] : '') + (groups[3] ? ' ' + groups[3] : '');

        let cursor = 0;
        let seen = 0;
        while (cursor < input.value.length && seen < digitsBeforeCursor) {
            if (/\d/.test(input.value[cursor])) seen++;
            cursor++;
        }
        input.setSelectionRange(cursor, cursor);
    }
}
