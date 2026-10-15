import { Controller } from '@hotwired/stimulus';

/**
 * Выбор позиций на странице выдачи СИЗ + подтверждение группового действия модалкой.
 * Прогрессивно: без JS чекбоксы работают нативно, а кнопка савбара сабмитит форму напрямую (бэк пишет только
 * отмеченные). С JS: отметка позиции раскрывает её поля (collapse), «Выбрать все» — все сразу, счётчик обновляется,
 * а «Выдать выбранные» сперва показывает подтверждение со списком.
 */
export default class extends Controller {
    static targets = ['check', 'all', 'count', 'modal', 'list'];

    connect() {
        this._recount();
    }

    // клик по чекбоксу не должен срабатывать как раскрытие карточки (шапка — тоггл collapse)
    stop(event) {
        event.stopPropagation();
    }

    toggle(event) {
        this._setExpanded(event.target, event.target.checked);
        this._recount();
    }

    toggleAll(event) {
        const on = event.target.checked;
        this.checkTargets.forEach((cb) => {
            cb.checked = on;
            this._setExpanded(cb, on);
        });
        this._recount();
    }

    // «Выдать выбранные»: с JS — подтверждение; без JS сюда не попадаем (нативный сабмит формы)
    confirmIssue(event) {
        event.preventDefault();
        const items = this._selectedItems();
        if (items.length === 0) {
            return;
        }
        this.kind = 'issue';
        this._fillModal('Записать выдачу', `Записать выдачу — позиций: ${items.length}`, items);
        this._modal().show();
    }

    confirm() {
        this._modal().hide();
        if (this.kind === 'issue') {
            document.getElementById('issue-form')?.requestSubmit();
        }
    }

    _selected() {
        return this.checkTargets.filter((cb) => cb.checked);
    }

    // отмеченные позиции с их текущим количеством (из поля «Кол-во» строки) — для списка в модалке
    _selectedItems() {
        return this._selected().map((cb) => {
            const amountEl = cb.closest('[data-row]')?.querySelector('input[name$="[amount]"]');
            const amount = amountEl ? (amountEl.value || '').trim() : '';
            const unit = amountEl?.dataset.unitTitle || '';
            return { label: cb.dataset.label || '', qty: amount !== '' ? `${amount} ${unit}`.trim() : '' };
        });
    }

    _setExpanded(checkbox, on) {
        const fields = checkbox.closest('[data-row]')?.querySelector('[data-issue-fields]');
        if (fields && window.bootstrap) {
            window.bootstrap.Collapse.getOrCreateInstance(fields, { toggle: false })[on ? 'show' : 'hide']();
        }
    }

    _recount() {
        const n = this._selected().length;
        this.countTargets.forEach((el) => {
            el.textContent = String(n);
        });
        if (this.hasAllTarget) {
            const total = this.checkTargets.length;
            this.allTarget.checked = n > 0 && n === total;
            this.allTarget.indeterminate = n > 0 && n < total;
        }
    }

    _fillModal(title, subtitle, items) {
        const m = this.modalTarget;
        m.querySelector('[data-role="title"]').textContent = title;
        m.querySelector('[data-role="subtitle"]').textContent = subtitle;
        this.listTarget.innerHTML = '';
        items.forEach(({ label, qty }) => {
            const li = document.createElement('li');
            li.className = 'd-flex align-items-center gap-2';
            const dot = document.createElement('i');
            dot.className = 'bi bi-dot';
            li.appendChild(dot);
            li.appendChild(document.createTextNode(label));
            if (qty) {
                const q = document.createElement('span');
                q.className = 'ms-auto text-body-secondary small';
                q.textContent = qty;
                li.appendChild(q);
            }
            this.listTarget.appendChild(li);
        });
    }

    _modal() {
        return window.bootstrap.Modal.getOrCreateInstance(this.modalTarget);
    }
}
