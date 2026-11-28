const { test, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element(tag = 'div') {
    return {
        tagName: tag.toUpperCase(),
        hidden: false,
        disabled: false,
        value: '',
        name: '',
        attributes: {},
        listeners: {},
        events: [],
        children: [],
        focused: false,
        removed: false,
        setAttribute(name, value) { this.attributes[name] = String(value); },
        addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); },
        removeEventListener(type, listener) { this.listeners[type] = (this.listeners[type] || []).filter((item) => item !== listener); },
        dispatchEvent(event) {
            this.events.push(event.type);
            for (const listener of this.listeners[event.type] || []) listener(event);
            return true;
        },
        contains(target) { return this === target || this.children.includes(target); },
        appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
        remove() { this.removed = true; },
        focus() { this.focused = true; },
        querySelector() { return null; },
    };
}

const document = element('document');
document.body = element('body');
const window = element('window');

class FakeCalendar {
    static instances = [];
    static failNextInit = false;

    constructor(calendarElement, options) {
        this.calendarElement = calendarElement;
        Object.assign(this, options);
        this.context = { isInit: false, isDestroyed: false, selectedDates: [] };
        FakeCalendar.instances.push(this);
    }

    init() {
        if (FakeCalendar.failNextInit) {
            FakeCalendar.failNextInit = false;
            throw new Error('Calendar init failed');
        }
        this.context.isInit = true;
        this.context.selectedDates = [...this.selectedDates];
    }

    set(options) {
        Object.assign(this, options);
        if (Object.hasOwn(options, 'selectedDates')) this.context.selectedDates = [...options.selectedDates];
    }

    select(date) {
        this.context.selectedDates = [date];
        this.onClickDate();
    }

    destroy() {
        this.context.isDestroyed = true;
        this.context.isInit = false;
    }
}

const poppers = [];
function createPopper(display, popup, options) {
    const popper = { display, popup, options, updated: false, destroyed: false, update() { this.updated = true; }, destroy() { this.destroyed = true; } };
    poppers.push(popper);
    return popper;
}

beforeEach(() => {
    document.listeners = {};
    document.body.children = [];
    window.listeners = {};
    poppers.length = 0;
    FakeCalendar.instances = [];
    FakeCalendar.failNextInit = false;
});

const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/date_picker_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace("import { createPopper } from '@popperjs/core';", '')
    .replace("import { Calendar, months } from 'vanilla-calendar-pro';", '')
    .replace("import { calendarResetOptions, russianCalendarLabels } from './date_range_controller.js';", '')
    .replace('export default class extends Controller', 'class DatePickerController extends Controller');
const DatePickerController = vm.runInNewContext(`${source}\nDatePickerController;`, {
    Calendar: FakeCalendar,
    months: {},
    createPopper,
    document,
    window,
    calendarResetOptions: { year: false, month: false, dates: false, time: false, locale: false },
    russianCalendarLabels: { application: 'Календарь' },
    Event: class Event { constructor(type, options = {}) { this.type = type; this.bubbles = options.bubbles; } },
});

function connectedPicker({ value = '2026-11-03', datetime = false } = {}) {
    const form = element('form');
    const root = element('div');
    root.closest = (selector) => selector === 'form' ? form : null;
    const controller = new DatePickerController();
    controller.element = root;
    controller.inputTarget = element('input');
    controller.inputTarget.name = datetime ? 'refuelings[0][date]' : 'trips[0][date]';
    controller.inputTarget.value = value;
    controller.enhancedTarget = element();
    controller.enhancedTarget.hidden = true;
    controller.displayTarget = element('input');
    controller.popup = element();
    controller.popup.hidden = true;
    controller.calendarTarget = element();
    controller.popupTarget = controller.popup;
    controller.popup.appendChild(controller.calendarTarget);
    controller.hasTimeTarget = datetime;
    if (datetime) controller.timeTarget = element('input');
    controller.localeValue = 'ru-RU';
    controller.firstWeekdayValue = 1;
    controller.minDateValue = '0001-01-01';
    controller.maxDateValue = '9999-12-31';
    controller.connect();
    return { controller, form };
}

test('successful initialization preserves the original field and shows a local calendar date', () => {
    const { controller } = connectedPicker();

    assert.equal(controller.inputTarget.hidden, true);
    assert.equal(controller.enhancedTarget.hidden, false);
    assert.equal(controller.inputTarget.value, '2026-11-03');
    assert.equal(controller.inputTarget.name, 'trips[0][date]');
    assert.deepEqual(controller.inputTarget.events, []);
    assert.equal(controller.displayTarget.value, '03.11.2026');
    assert.equal(FakeCalendar.instances[0].selectionDatesMode, 'single');
    assert.equal(FakeCalendar.instances[0].locale, 'ru-RU');
    assert.equal(FakeCalendar.instances[0].firstWeekday, 1);
    assert.deepEqual(FakeCalendar.instances[0].context.selectedDates, ['2026-11-03']);
});

test('empty and invalid values are not replaced with today or zeros', () => {
    for (const value of ['', 'invalid']) {
        const { controller } = connectedPicker({ value });
        assert.equal(controller.inputTarget.value, value);
        assert.equal(controller.displayTarget.value, '');
        assert.deepEqual(FakeCalendar.instances.at(-1).context.selectedDates, []);
    }
});

test('initialization failure leaves the original accessible field visible', () => {
    FakeCalendar.failNextInit = true;
    const { controller } = connectedPicker();

    assert.equal(controller.inputTarget.hidden, false);
    assert.equal(controller.enhancedTarget.hidden, true);
    assert.equal(controller.popup.hidden, true);
    assert.equal(document.listeners.click.length, 0);
});

test('selecting one day commits the original value once and closes with focus restored', () => {
    const { controller } = connectedPicker();
    controller.open();
    const calendar = FakeCalendar.instances[0];

    calendar.select('2028-02-29');

    assert.equal(controller.inputTarget.value, '2028-02-29');
    assert.deepEqual(controller.inputTarget.events, ['input', 'change']);
    assert.equal(controller.displayTarget.value, '29.02.2028');
    assert.equal(controller.isOpen, false);
    assert.equal(controller.displayTarget.focused, true);
    assert.equal(poppers[0].options.strategy, 'fixed');
    assert.equal(poppers[0].options.modifiers[2].name, 'preventOverflow');
});

test('date selection preserves the exact refueling time including seconds', () => {
    const { controller } = connectedPicker({ value: '2026-11-03T09:14:30', datetime: true });
    assert.equal(controller.timeTarget.value, '09:14:30');
    controller.open();

    FakeCalendar.instances[0].select('2026-11-04');

    assert.equal(controller.inputTarget.value, '2026-11-04T09:14:30');
    assert.equal(controller.displayTarget.value, '04.11.2026');
    assert.deepEqual(controller.inputTarget.events, ['input', 'change']);
});

test('an empty datetime is completed explicitly without inventing a time and can be cleared safely', () => {
    const { controller, form } = connectedPicker({ value: '', datetime: true });
    controller.open();
    FakeCalendar.instances[0].select('2026-11-04');

    assert.equal(controller.inputTarget.value, '');
    assert.equal(controller.displayTarget.value, '04.11.2026');
    assert.equal(controller.timeTarget.focused, true);

    controller.timeTarget.value = '08:05:06';
    controller.changeTime();
    assert.equal(controller.inputTarget.value, '2026-11-04T08:05:06');
    assert.deepEqual(controller.inputTarget.events, ['input', 'change']);

    controller.timeTarget.value = '';
    controller.changeTime();
    assert.equal(controller.inputTarget.value, '');
    assert.equal(controller.displayTarget.value, '04.11.2026');
    const events = [...form.listeners.submit];
    for (const listener of events) listener();
    assert.equal(controller.inputTarget.value, '');
});

test('keyboard, outside click and external changes do not create a form submission', () => {
    const { controller, form } = connectedPicker();
    const key = { key: 'Enter', prevented: false, preventDefault() { this.prevented = true; } };
    controller.onDisplayKeydown(key);
    assert.equal(key.prevented, true);
    assert.equal(controller.isOpen, true);
    assert.equal(form.events.length, 0);

    controller.onDocumentKeydown({ key: 'Escape', preventDefault() {} });
    assert.equal(controller.isOpen, false);
    assert.equal(controller.displayTarget.focused, true);

    controller.open();
    controller.onDocumentClick({ target: element('button') });
    assert.equal(controller.isOpen, false);

    controller.inputTarget.value = '2026-12-01';
    controller.inputTarget.dispatchEvent(new Event('change'));
    assert.equal(controller.displayTarget.value, '01.12.2026');
    assert.deepEqual(controller.inputTarget.events, ['change']);
});

test('disconnect restores the original input and cleans popup and listeners', () => {
    const { controller } = connectedPicker();
    controller.open();
    controller.disconnect();

    assert.equal(controller.inputTarget.hidden, false);
    assert.equal(controller.enhancedTarget.hidden, true);
    assert.equal(controller.popup.removed, true);
    assert.equal(poppers[0].destroyed, true);
    assert.equal(document.listeners.click.length, 0);
    assert.equal(document.listeners.keydown.length, 0);
});
