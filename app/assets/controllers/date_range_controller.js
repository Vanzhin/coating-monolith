import { Controller } from '@hotwired/stimulus';
import { createPopper } from '@popperjs/core';
import { Calendar, months } from 'vanilla-calendar-pro';

export const calendarResetOptions = { year: false, month: false, dates: false, time: false, locale: false };
export const russianCalendarLabels = {
    application: 'Календарь',
    navigation: 'Навигация по календарю',
    arrowNext: { month: 'Следующий месяц', year: 'Следующий список лет', week: 'Следующая неделя' },
    arrowPrev: { month: 'Предыдущий месяц', year: 'Предыдущий список лет', week: 'Предыдущая неделя' },
    month: 'Выбрать месяц, текущий месяц:',
    months: 'Список месяцев',
    year: 'Выбрать год, текущий год:',
    years: 'Список лет',
    week: 'Дни недели',
    weekNumber: 'Номера недель в году',
    collapse: 'Свернуть до одной недели',
    expand: 'Развернуть весь месяц',
    dates: 'Даты текущего месяца',
};

export default class extends Controller {
    static targets = ['from', 'to', 'fallback', 'enhanced', 'display', 'popup', 'calendar'];
    static values = {
        locale: { type: String, default: 'ru-RU' },
        firstWeekday: { type: Number, default: 1 },
        minDate: { type: String, default: '0001-01-01' },
        maxDate: { type: String, default: '9999-12-31' },
        months: { type: Number, default: 2 },
        wideWidth: { type: Number, default: 640 },
    };

    connect() {
        this.isOpen = false;
        this.pending = false;
        this.syncing = false;
        this.popup = this.popupTarget;
        this.calendarElement = this.calendarTarget;
        this.form = this.element.closest('form');
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.onDocumentKeydown = this.onDocumentKeydown.bind(this);
        this.onSubmit = this.onSubmit.bind(this);
        this.onResize = this.onResize.bind(this);
        this.onInputChange = this.onInputChange.bind(this);

        try {
            this.calendar = new Calendar(this.calendarElement, this.calendarOptions());
            this.calendar.init();
            this.updateDisplay();
            this.fallbackTarget.hidden = true;
            this.enhancedTarget.hidden = false;
            document.addEventListener('click', this.onDocumentClick);
            document.addEventListener('keydown', this.onDocumentKeydown);
            window.addEventListener('resize', this.onResize);
            this.fromTarget.addEventListener('change', this.onInputChange);
            this.toTarget.addEventListener('change', this.onInputChange);
            this.form?.addEventListener('submit', this.onSubmit, true);
        } catch (error) {
            this.restoreFallback();
        }
    }

    disconnect() {
        this.removeListeners();
        this.destroyPopper();
        if (this.calendar?.context?.isInit && !this.calendar.context.isDestroyed) {
            this.calendar.destroy();
        }
        this.popup?.remove();
        this.calendar = null;
        this.isOpen = false;
        this.fallbackTarget.hidden = false;
        this.enhancedTarget.hidden = true;
    }

    toggle(event) {
        event.preventDefault();
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    onDisplayKeydown(event) {
        if (event.key === 'Escape' && this.isOpen) {
            event.preventDefault();
            this.close(true);
            return;
        }
        if (!['Enter', ' ', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        if (event.key === 'ArrowDown') {
            if (!this.isOpen) this.open();
            this.focusCalendar();
            return;
        }
        this.toggle(event);
    }

    open() {
        if (this.isOpen || !this.calendar || this.displayTarget.disabled) return;
        this.pending = false;
        this.resetCalendar();
        document.body.appendChild(this.popup);
        this.popup.hidden = false;
        this.displayTarget.setAttribute('aria-expanded', 'true');
        this.isOpen = true;
        this.popper = createPopper(this.displayTarget, this.popup, {
            strategy: 'fixed',
            placement: 'bottom-start',
            modifiers: [
                { name: 'offset', options: { offset: [0, 4] } },
                { name: 'flip', options: { fallbackPlacements: ['top-start', 'bottom-end', 'top-end'] } },
                { name: 'preventOverflow', options: { altAxis: true, padding: 8 } },
            ],
        });
    }

    close(restoreFocus = false) {
        if (!this.isOpen) return;
        this.cancelPending();
        this.isOpen = false;
        this.popup.hidden = true;
        this.displayTarget.setAttribute('aria-expanded', 'false');
        this.destroyPopper();
        if (restoreFocus) this.displayTarget.focus();
    }

    onClickDate() {
        const dates = this.calendar.context.selectedDates;
        if (dates.length === 1) {
            this.pending = true;
            return;
        }
        if (dates.length < 2) return;

        this.pending = false;
        this.commit(dates[0], dates[dates.length - 1]);
        this.close(true);
    }

    commit(from, to) {
        this.syncing = true;
        try {
            this.setInput(this.fromTarget, from);
            this.setInput(this.toTarget, to);
        } finally {
            this.syncing = false;
        }
        this.updateDisplay();
    }

    setInput(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    onInputChange() {
        if (this.syncing) return;
        this.pending = false;
        this.updateDisplay();
        this.resetCalendar();
    }

    onSubmit() {
        this.cancelPending();
        this.close();
    }

    onDocumentClick(event) {
        if (!this.isOpen) return;
        if (this.popup.contains(event.target) || this.displayTarget.contains(event.target)) return;
        this.close();
    }

    onDocumentKeydown(event) {
        if (event.key !== 'Escape' || !this.isOpen) return;
        event.preventDefault();
        this.close(true);
    }

    onResize() {
        if (!this.calendar) return;
        const multiple = this.useMultipleMonths();
        if ((this.calendar.type === 'multiple') !== multiple) {
            this.calendar.set(this.monthOptions(), calendarResetOptions);
        }
        this.popper?.update();
    }

    calendarOptions() {
        return {
            ...this.monthOptions(),
            extensions: [months],
            selectionDatesMode: 'multiple-ranged',
            enableDateToggle: false,
            enableEdgeDatesOnly: true,
            enableJumpToSelectedDate: true,
            firstWeekday: this.firstWeekdayValue,
            locale: this.localeValue,
            dateMin: this.minDateValue,
            dateMax: this.maxDateValue,
            selectedDates: this.selectedDates(),
            selectedTheme: 'system',
            themeAttrDetect: 'html[data-bs-theme]',
            labels: russianCalendarLabels,
            onClickDate: () => this.onClickDate(),
        };
    }

    monthOptions() {
        const multiple = this.useMultipleMonths();
        return { type: multiple ? 'multiple' : 'default', displayMonthsCount: multiple ? this.monthsValue : 1 };
    }

    useMultipleMonths() {
        return this.monthsValue > 1 && window.innerWidth >= this.wideWidthValue;
    }

    selectedDates() {
        const from = this.validDate(this.fromTarget.value);
        const to = this.validDate(this.toTarget.value);
        if (from && to) return from <= to ? [`${from}:${to}`] : [`${to}:${from}`];
        return [from, to].filter(Boolean);
    }

    validDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null;
    }

    resetCalendar() {
        if (!this.calendar) return;
        this.calendar.set({ selectedDates: this.selectedDates() }, calendarResetOptions);
    }

    cancelPending() {
        if (!this.pending) return;
        this.pending = false;
        this.updateDisplay();
        this.resetCalendar();
    }

    updateDisplay() {
        const from = this.formatDate(this.fromTarget.value);
        const to = this.formatDate(this.toTarget.value);
        this.displayTarget.value = from || to ? `${from || '…'} — ${to || '…'}` : '';
    }

    formatDate(value) {
        return this.validDate(value) ? value.split('-').reverse().join('.') : '';
    }

    focusCalendar() {
        this.calendarElement.querySelector('[data-vc-date-selected] [data-vc-date-btn], [data-vc-date-btn]')?.focus();
    }

    destroyPopper() {
        this.popper?.destroy();
        this.popper = null;
    }

    removeListeners() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onDocumentKeydown);
        window.removeEventListener('resize', this.onResize);
        this.fromTarget.removeEventListener('change', this.onInputChange);
        this.toTarget.removeEventListener('change', this.onInputChange);
        this.form?.removeEventListener('submit', this.onSubmit, true);
    }

    restoreFallback() {
        this.removeListeners();
        this.destroyPopper();
        if (this.calendar?.context?.isInit && !this.calendar.context.isDestroyed) {
            this.calendar.destroy();
        }
        this.calendar = null;
        this.popup.hidden = true;
        this.fallbackTarget.hidden = false;
        this.enhancedTarget.hidden = true;
        this.displayTarget.setAttribute('aria-expanded', 'false');
    }
}
