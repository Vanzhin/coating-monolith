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
        focused: false,
        removed: false,
        children: [],
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
window.innerWidth = 1024;

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
        this.context.selectedDates = this.expand(this.selectedDates);
    }

    set(options) {
        Object.assign(this, options);
        if (Object.hasOwn(options, 'selectedDates')) this.context.selectedDates = this.expand(options.selectedDates);
    }

    select(dates) {
        this.context.selectedDates = [...dates].sort();
        this.onClickDate();
    }

    expand(dates) {
        const expanded = [];
        for (const date of dates || []) {
            if (date.includes(':')) {
                const [from, to] = date.split(':');
                expanded.push(...(from === to ? [from, from] : [from, to]));
            } else if (date) {
                expanded.push(date);
            }
        }
        return expanded;
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
    window.innerWidth = 1024;
    poppers.length = 0;
    FakeCalendar.instances = [];
    FakeCalendar.failNextInit = false;
});

const source = fs.readFileSync(path.resolve(__dirname, '../../../../../assets/controllers/date_range_controller.js'), 'utf8')
    .replace("import { Controller } from '@hotwired/stimulus';", 'class Controller {}')
    .replace("import { createPopper } from '@popperjs/core';", '')
    .replace("import { Calendar, months } from 'vanilla-calendar-pro';", '')
    .replace('export const calendarResetOptions =', 'const calendarResetOptions =')
    .replace('export const russianCalendarLabels =', 'const russianCalendarLabels =')
    .replace('export default class extends Controller', 'class DateRangeController extends Controller');
const DateRangeController = vm.runInNewContext(`${source}\nDateRangeController;`, {
    Calendar: FakeCalendar,
    months: {},
    createPopper,
    document,
    window,
    Event: class Event { constructor(type, options = {}) { this.type = type; this.bubbles = options.bubbles; } },
});

function connectedRange({ from = '2026-11-01', to = '2026-11-30', width = 1024 } = {}) {
    window.innerWidth = width;
    const form = element('form');
    const root = element('section');
    root.closest = (selector) => selector === 'form' ? form : null;
    const controller = new DateRangeController();
    controller.element = root;
    controller.fromTarget = element('input');
    controller.fromTarget.name = 'from';
    controller.fromTarget.value = from;
    controller.toTarget = element('input');
    controller.toTarget.name = 'to';
    controller.toTarget.value = to;
    controller.fallbackTarget = element();
    controller.enhancedTarget = element();
    controller.enhancedTarget.hidden = true;
    controller.displayTarget = element('input');
    controller.popup = element();
    controller.popup.hidden = true;
    controller.calendarTarget = element();
    controller.popupTarget = controller.popup;
    controller.popup.appendChild(controller.calendarTarget);
    controller.localeValue = 'ru-RU';
    controller.firstWeekdayValue = 1;
    controller.minDateValue = '0001-01-01';
    controller.maxDateValue = '9999-12-31';
    controller.monthsValue = 2;
    controller.wideWidthValue = 640;
    controller.connect();

    return { controller, form, calendar: FakeCalendar.instances.at(-1) };
}

function preventDefaultEvent(extra = {}) {
    return { prevented: false, preventDefault() { this.prevented = true; }, ...extra };
}

test('successful initialization preserves the POST dates and enhances the visible control', () => {
    const { controller, calendar } = connectedRange();

    assert.equal(controller.fallbackTarget.hidden, true);
    assert.equal(controller.enhancedTarget.hidden, false);
    assert.equal(controller.displayTarget.value, '01.11.2026 — 30.11.2026');
    assert.equal(controller.fromTarget.value, '2026-11-01');
    assert.equal(controller.toTarget.value, '2026-11-30');
    assert.deepEqual(controller.fromTarget.events, []);
    assert.deepEqual(controller.toTarget.events, []);
    assert.equal(calendar.selectionDatesMode, 'multiple-ranged');
    assert.equal(calendar.locale, 'ru-RU');
    assert.equal(calendar.firstWeekday, 1);
    assert.equal(calendar.enableDateToggle, false);
    assert.equal(calendar.type, 'multiple');
    assert.equal(calendar.displayMonthsCount, 2);
    assert.equal(calendar.labels.arrowNext.month, 'Следующий месяц');
    assert.equal(calendar.themeAttrDetect, 'html[data-bs-theme]');
    assert.equal(controller.displayTarget.name, '');
    assert.equal(source.includes('toISOString'), false);
});

test('empty and partially returned periods stay empty or partial without current-date substitution', () => {
    const empty = connectedRange({ from: '', to: '' }).controller;
    assert.equal(empty.displayTarget.value, '');
    assert.equal(empty.fromTarget.value, '');
    assert.equal(empty.toTarget.value, '');

    const onlyFrom = connectedRange({ from: '2024-02-29', to: '' }).controller;
    assert.equal(onlyFrom.displayTarget.value, '29.02.2024 — …');
    assert.equal(onlyFrom.fromTarget.value, '2024-02-29');
    assert.equal(onlyFrom.toTarget.value, '');

    const onlyTo = connectedRange({ from: '', to: '2026-01-02' }).controller;
    assert.equal(onlyTo.displayTarget.value, '… — 02.01.2026');
    assert.equal(onlyTo.fromTarget.value, '');
    assert.equal(onlyTo.toTarget.value, '2026-01-02');
});

test('initialization failure leaves the two accessible fallback fields visible', () => {
    FakeCalendar.failNextInit = true;
    const { controller } = connectedRange();

    assert.equal(controller.calendar, null);
    assert.equal(controller.fallbackTarget.hidden, false);
    assert.equal(controller.enhancedTarget.hidden, true);
    assert.equal(controller.popup.hidden, true);
    assert.equal(controller.fromTarget.value, '2026-11-01');
    assert.equal(controller.toTarget.value, '2026-11-30');
    assert.deepEqual(document.listeners.click, []);
    assert.deepEqual(document.listeners.keydown, []);
});

test('first selection does not change fields; complete forward and reverse ranges sync each field once', () => {
    const { controller, calendar } = connectedRange();
    controller.open();
    calendar.select(['2026-12-31']);

    assert.equal(controller.pending, true);
    assert.equal(controller.fromTarget.value, '2026-11-01');
    assert.equal(controller.toTarget.value, '2026-11-30');
    assert.equal(controller.displayTarget.value, '01.11.2026 — 30.11.2026');

    calendar.select(['2026-12-31', '2027-01-02']);
    assert.equal(controller.fromTarget.value, '2026-12-31');
    assert.equal(controller.toTarget.value, '2027-01-02');
    assert.equal(controller.displayTarget.value, '31.12.2026 — 02.01.2027');
    assert.deepEqual(controller.fromTarget.events, ['input', 'change']);
    assert.deepEqual(controller.toTarget.events, ['input', 'change']);
    assert.equal(controller.isOpen, false);

    controller.open();
    calendar.select(['2027-01-02', '2026-12-31']);
    assert.equal(controller.fromTarget.value, '2026-12-31');
    assert.equal(controller.toTarget.value, '2027-01-02');
    assert.deepEqual(controller.fromTarget.events, ['input', 'change', 'input', 'change']);
    assert.deepEqual(controller.toTarget.events, ['input', 'change', 'input', 'change']);
});

test('equal dates select one full day including leap day without timezone conversion', () => {
    for (const date of ['2024-02-29', '2026-03-29', '2026-10-25']) {
        const { controller, calendar } = connectedRange({ from: '', to: '' });
        controller.open();
        calendar.select([date, date]);

        assert.equal(controller.fromTarget.value, date);
        assert.equal(controller.toTarget.value, date);
        assert.equal(controller.displayTarget.value, `${date.split('-').reverse().join('.')} — ${date.split('-').reverse().join('.')}`);
    }
});

test('Escape, outside click and submit cancel only the pending calendar selection', () => {
    for (const cancel of ['Escape', 'outside', 'submit']) {
        const { controller, form, calendar } = connectedRange();
        controller.open();
        calendar.select(['2026-11-15']);
        const event = preventDefaultEvent({ key: 'Escape', target: element() });

        if (cancel === 'Escape') controller.onDocumentKeydown(event);
        if (cancel === 'outside') controller.onDocumentClick(event);
        if (cancel === 'submit') form.dispatchEvent({ type: 'submit' });

        assert.equal(controller.pending, false, cancel);
        assert.equal(controller.isOpen, false, cancel);
        assert.equal(controller.fromTarget.value, '2026-11-01', cancel);
        assert.equal(controller.toTarget.value, '2026-11-30', cancel);
        assert.equal(controller.displayTarget.value, '01.11.2026 — 30.11.2026', cancel);
        assert.deepEqual(calendar.context.selectedDates, ['2026-11-01', '2026-11-30'], cancel);
        assert.deepEqual(controller.fromTarget.events, [], cancel);
        assert.deepEqual(controller.toTarget.events, [], cancel);
    }
});

test('calendar keyboard handling prevents Enter submission and supports ArrowDown and Escape', () => {
    const { controller } = connectedRange();
    const enter = preventDefaultEvent({ key: 'Enter' });
    controller.onDisplayKeydown(enter);
    assert.equal(enter.prevented, true);
    assert.equal(controller.isOpen, true);

    controller.calendarElement.querySelector = () => controller.calendarElement;
    const down = preventDefaultEvent({ key: 'ArrowDown' });
    controller.onDisplayKeydown(down);
    assert.equal(down.prevented, true);
    assert.equal(controller.calendarElement.focused, true);

    const escape = preventDefaultEvent({ key: 'Escape' });
    controller.onDisplayKeydown(escape);
    assert.equal(escape.prevented, true);
    assert.equal(controller.isOpen, false);
    assert.equal(controller.displayTarget.focused, true);
    assert.equal(controller.displayTarget.attributes['aria-expanded'], 'false');
});

test('popup uses fixed Popper positioning with overflow protection', () => {
    const { controller } = connectedRange({ width: 390 });
    controller.open();

    assert.equal(poppers.length, 1);
    assert.equal(poppers[0].options.strategy, 'fixed');
    assert.equal(poppers[0].options.modifiers.find((item) => item.name === 'preventOverflow').options.padding, 8);
    assert.equal(controller.popup.hidden, false);
    assert.equal(controller.displayTarget.attributes['aria-expanded'], 'true');
    controller.close();
    assert.equal(poppers[0].destroyed, true);
    assert.equal(controller.popup.hidden, true);
});

test('width changes switch month count without losing a complete or pending selection', () => {
    const { controller, calendar } = connectedRange();
    controller.open();
    calendar.select(['2026-11-15']);
    window.innerWidth = 390;
    controller.onResize();

    assert.equal(calendar.type, 'default');
    assert.equal(calendar.displayMonthsCount, 1);
    assert.deepEqual(calendar.context.selectedDates, ['2026-11-15']);
    assert.equal(controller.pending, true);
    assert.equal(poppers[0].updated, true);

    window.innerWidth = 1024;
    controller.onResize();
    assert.equal(calendar.type, 'multiple');
    assert.equal(calendar.displayMonthsCount, 2);
    assert.deepEqual(calendar.context.selectedDates, ['2026-11-15']);
    assert.equal(controller.fromTarget.value, '2026-11-01');
    assert.equal(controller.toTarget.value, '2026-11-30');
});

test('external partial input changes update only their representation', () => {
    const { controller } = connectedRange();
    controller.fromTarget.value = '';
    controller.toTarget.value = '2026-12-31';
    controller.toTarget.dispatchEvent({ type: 'change' });

    assert.equal(controller.displayTarget.value, '… — 31.12.2026');
    assert.equal(controller.fromTarget.value, '');
    assert.equal(controller.toTarget.value, '2026-12-31');
});

test('disconnect restores fallback and cleans popup, listeners and pending selection', () => {
    const { controller, calendar } = connectedRange();
    controller.open();
    calendar.select(['2026-11-15']);
    controller.disconnect();

    assert.equal(controller.calendar, null);
    assert.equal(calendar.context.isDestroyed, true);
    assert.equal(controller.popup.removed, true);
    assert.equal(controller.fallbackTarget.hidden, false);
    assert.equal(controller.enhancedTarget.hidden, true);
    assert.equal(controller.fromTarget.value, '2026-11-01');
    assert.equal(controller.toTarget.value, '2026-11-30');
    assert.deepEqual(document.listeners.click, []);
    assert.deepEqual(document.listeners.keydown, []);
    assert.deepEqual(window.listeners.resize, []);
});
