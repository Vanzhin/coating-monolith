import { Controller } from '@hotwired/stimulus';
import { createPopper } from '@popperjs/core';
import { Calendar, months } from 'vanilla-calendar-pro';
import { calendarResetOptions, russianCalendarLabels } from './date_range_controller.js';

export default class extends Controller {
    static targets = ['input', 'enhanced', 'display', 'time', 'popup', 'calendar'];
    static values = {
        locale: { type: String, default: 'ru-RU' },
        firstWeekday: { type: Number, default: 1 },
        minDate: { type: String, default: '0001-01-01' },
        maxDate: { type: String, default: '9999-12-31' },
    };

    connect() {
        this.isOpen = false;
        this.syncing = false;
        this.draftDate = this.inputDate();
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
            if (this.hasTimeTarget) this.timeTarget.value = this.inputTime();
            this.updateDisplay();
            this.inputTarget.hidden = true;
            this.enhancedTarget.hidden = false;
            document.addEventListener('click', this.onDocumentClick);
            document.addEventListener('keydown', this.onDocumentKeydown);
            window.addEventListener('resize', this.onResize);
            this.inputTarget.addEventListener('change', this.onInputChange);
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
        this.inputTarget.hidden = false;
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
        this.isOpen = false;
        this.popup.hidden = true;
        this.displayTarget.setAttribute('aria-expanded', 'false');
        this.destroyPopper();
        if (restoreFocus) this.displayTarget.focus();
    }

    onClickDate() {
        const date = this.calendar.context.selectedDates.at(-1);
        if (!this.validDate(date)) return;
        this.draftDate = date;
        if (this.hasTimeTarget && !this.timeTarget.value) {
            this.updateDisplay();
            this.close();
            this.timeTarget.focus();
            return;
        }
        this.commit(date, this.hasTimeTarget ? this.timeTarget.value : '');
        this.close(true);
    }

    changeTime() {
        const date = this.draftDate || this.inputDate();
        const time = this.timeTarget.value;
        if (!date || !time) {
            this.syncing = true;
            try {
                if (this.inputTarget.value) this.setInput('');
            } finally {
                this.syncing = false;
            }
            this.updateDisplay();
            return;
        }
        this.commit(date, time);
    }

    commit(date, time) {
        const value = this.hasTimeTarget ? `${date}T${time}` : date;
        this.draftDate = date;
        this.syncing = true;
        try {
            this.setInput(value);
        } finally {
            this.syncing = false;
        }
        this.updateDisplay();
        this.resetCalendar();
    }

    setInput(value) {
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    onInputChange() {
        if (this.syncing) return;
        this.draftDate = this.inputDate();
        if (this.hasTimeTarget) this.timeTarget.value = this.inputTime();
        this.updateDisplay();
        this.resetCalendar();
    }

    onSubmit() {
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
        this.popper?.update();
    }

    calendarOptions() {
        return {
            type: 'default',
            displayMonthsCount: 1,
            extensions: [months],
            selectionDatesMode: 'single',
            enableDateToggle: false,
            enableEdgeDatesOnly: true,
            enableJumpToSelectedDate: true,
            firstWeekday: this.firstWeekdayValue,
            locale: this.localeValue,
            dateMin: this.minDateValue,
            dateMax: this.maxDateValue,
            selectedDates: this.draftDate ? [this.draftDate] : [],
            selectedTheme: 'system',
            themeAttrDetect: 'html[data-bs-theme]',
            labels: russianCalendarLabels,
            onClickDate: () => this.onClickDate(),
        };
    }

    resetCalendar() {
        if (!this.calendar) return;
        this.calendar.set({ selectedDates: this.draftDate ? [this.draftDate] : [] }, calendarResetOptions);
    }

    inputDate() {
        return this.validDate(this.inputTarget.value.slice(0, 10));
    }

    inputTime() {
        return this.inputTarget.value.includes('T') ? this.inputTarget.value.split('T')[1] : '';
    }

    validDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : null;
    }

    updateDisplay() {
        const date = this.draftDate || this.inputDate();
        this.displayTarget.value = date ? date.split('-').reverse().join('.') : '';
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
        this.inputTarget.removeEventListener('change', this.onInputChange);
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
        this.inputTarget.hidden = false;
        this.enhancedTarget.hidden = true;
        this.displayTarget.setAttribute('aria-expanded', 'false');
    }
}
