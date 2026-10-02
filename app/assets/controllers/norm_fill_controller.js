import { Controller } from '@hotwired/stimulus';

/**
 * Помощник формы оформления выдачи:
 *  - fill()    — «Заполнить по норме»: кол-во (data-norm-amount) + единица (data-norm-unit).
 *  - check()   — подсветка введённого кол-ва: меньше нормы → красное (is-invalid), ровно/больше → зелёное.
 *  - nextDue() — при смене даты шлёт запрос; СЧИТАЕТ БЭК (домен), фронт только показывает (data-next-due).
 */
export default class extends Controller {
    static values = { nextDueUrl: String };

    connect() {
        this.element.querySelectorAll('[data-norm-amount]').forEach((el) => this._mark(el));
        this.element.querySelectorAll('[data-issue-date]').forEach((el) => this._nextDue(el));
    }

    fill() {
        this.element.querySelectorAll('[data-norm-amount]').forEach((el) => {
            if (el.dataset.normAmount) {
                el.value = el.dataset.normAmount;
                this._mark(el);
            }
        });
        this.element.querySelectorAll('[data-norm-unit]').forEach((el) => {
            if (el.dataset.normUnit) {
                el.value = el.dataset.normUnit;
            }
        });
        // заполнение — тоже изменение: пересчитываем след. выдачу по всем строкам
        this.element.querySelectorAll('[data-issue-date]').forEach((el) => this._nextDue(el));
    }

    check(event) {
        this._mark(event.target);
        this._scheduleRowDue(event.target); // ввод количества — тоже пересчитывает дату строки (дебаунс)
    }

    nextDue(event) {
        this._nextDue(event.target);
    }

    // смена количества/единицы (change/blur) — мгновенный пересчёт даты для своей строки
    rowDue(event) {
        this._rowDue(event.target);
    }

    _rowDue(fieldEl) {
        const dateEl = fieldEl.closest('[data-row]')?.querySelector('[data-issue-date]');
        if (dateEl) {
            this._nextDue(dateEl);
        }
    }

    _scheduleRowDue(fieldEl) {
        clearTimeout(this._dueTimer);
        this._dueTimer = setTimeout(() => this._rowDue(fieldEl), 300);
    }

    _mark(el) {
        el.classList.remove('is-invalid', 'is-valid', 'is-over');
        const raw = (el.value || '').trim().replace(',', '.');
        if (raw === '') {
            return; // пусто — без подсветки
        }
        const val = parseFloat(raw);
        if (Number.isNaN(val) || val <= 0) {
            el.classList.add('is-invalid'); // 0 / мусор / отрицательное — красное (бэк: PositiveNumber, да и < нормы)
            return;
        }
        const norm = parseFloat(el.dataset.normAmount);
        if (Number.isNaN(norm)) {
            return; // нормы нет — не с чем сравнивать
        }
        if (val < norm) {
            el.classList.add('is-invalid'); // меньше нормы — красное
        } else if (val === norm) {
            el.classList.add('is-valid'); // ровно — зелёное
        } else {
            el.classList.add('is-over'); // больше — голубое
        }
    }

    async _nextDue(dateEl) {
        const out = dateEl.closest('[data-row]')?.querySelector('[data-next-due]');
        if (!out || !this.hasNextDueUrlValue) {
            return;
        }
        const params = new URLSearchParams({
            date: dateEl.value || '',
            kind: dateEl.dataset.cadenceKind || '',
            number: dateEl.dataset.cadenceNumber || '',
            unit: dateEl.dataset.cadenceUnit || '',
        });
        try {
            const res = await fetch(`${this.nextDueUrlValue}?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const json = await res.json();
            const payload = json.data ?? json; // глобальный ResponseListener оборачивает в {data}
            out.textContent = payload.nextDue || '—';
        } catch {
            out.textContent = '—';
        }
    }
}
