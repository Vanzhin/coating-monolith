import { Controller } from '@hotwired/stimulus';

/**
 * Выбор позиций на странице выдачи СИЗ + подтверждение группового действия модалкой.
 * Прогрессивно: без JS чекбоксы работают нативно, а кнопка савбара сабмитит форму напрямую (бэк пишет только
 * отмеченные). С JS: отметка позиции раскрывает её поля (collapse), «Выбрать все» — все сразу, счётчик обновляется,
 * а «Выдать выбранные» сперва показывает подтверждение со списком.
 */
export default class extends Controller {
    static targets = ['check', 'all', 'count', 'issueCount', 'modal', 'list', 'excludeModal', 'excludeLabel'];

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

    // «Выдать выбранные»: с JS — подтверждение; без JS сюда не попадаем (нативный сабмит формы). Строки
    // «только списать» (data-writeoff-only) исключены — тот же чекбокс служит и выбору для списания, но в
    // выдачу эта позиция не идёт (полей выдачи у неё нет вовсе).
    confirmIssue(event) {
        event.preventDefault();
        const items = this._selectedItems('amount', 'issue');
        if (items.length === 0) {
            return;
        }
        this.kind = 'issue';
        this._fillModal('Записать выдачу', `Записать выдачу — позиций: ${items.length}`, items);
        this._modal().show();
    }

    // «Списать выбранные»: подтверждение со списком (количество = на руках); только JS (без JS — «Списать» ниже).
    // Включает и обычные позиции, и строки «только списать» — списать можно и то, и то.
    confirmWriteoff(event) {
        event.preventDefault();
        const items = this._selectedItems('held', 'all');
        if (items.length === 0) {
            return;
        }
        this.kind = 'writeoff';
        this._fillModal('Списать выбранные', `Добавить в акт списания — позиций: ${items.length}`, items);
        this._modal().show();
    }

    confirm() {
        this._modal().hide();
        if (this.kind === 'writeoff') {
            this._submitWriteoff();
        } else {
            document.getElementById('issue-form')?.requestSubmit();
        }
    }

    // кладём ключи выбранных позиций в скрытую форму списания и сабмитим (наполнение корзины-акта); включает
    // и обычные позиции, и строки «только списать» — списать можно и то, и то.
    _submitWriteoff() {
        const form = document.getElementById('writeoff-add-form');
        if (!form) {
            return;
        }
        form.querySelectorAll('input[name="keys[]"]').forEach((el) => el.remove());
        this._selected('all').forEach((cb) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'keys[]';
            input.value = cb.dataset.key || '';
            form.appendChild(input);
        });
        form.requestSubmit();
    }

    // scope 'all' — все отмеченные чекбоксы (списание доступно и обычным позициям, и строкам «только списать»);
    // 'issue' — исключая data-writeoff-only (у такой строки полей выдачи нет вовсе, в «Выдать выбранные» не идёт).
    _selected(scope = 'all') {
        const checked = this.checkTargets.filter((cb) => cb.checked);

        return 'issue' === scope ? checked.filter((cb) => cb.dataset.writeoffOnly !== '1') : checked;
    }

    // отмеченные позиции с количеством для списка в модалке: source 'amount' — из поля «Кол-во» (выдача),
    // 'held' — на руках из data-атрибута (списание). Единица — с чекбокса. scope — см. _selected().
    _selectedItems(source = 'amount', scope = 'all') {
        return this._selected(scope).map((cb) => {
            const unit = cb.dataset.unitTitle || '';
            let value = '';
            if (source === 'held') {
                value = (cb.dataset.held || '').trim();
            } else {
                const amountEl = cb.closest('[data-row]')?.querySelector('input[name$="[amount]"]');
                value = amountEl ? (amountEl.value || '').trim() : '';
            }
            return { label: cb.dataset.label || '', qty: value !== '' ? `${value} ${unit}`.trim() : '' };
        });
    }

    _setExpanded(checkbox, on) {
        const fields = checkbox.closest('[data-row]')?.querySelector('[data-issue-fields]');
        if (fields && window.bootstrap) {
            window.bootstrap.Collapse.getOrCreateInstance(fields, { toggle: false })[on ? 'show' : 'hide']();
        }
    }

    // count — все отмеченные (списание); issueCount — отмеченные БЕЗ строк «только списать» (выдача).
    _recount() {
        const n = this._selected('all').length;
        const issueN = this._selected('issue').length;
        this.countTargets.forEach((el) => {
            el.textContent = String(n);
        });
        this.issueCountTargets.forEach((el) => {
            el.textContent = String(issueN);
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
            // Каждая позиция — отдельный «тонутый» блок (как строки истории/списания): имя слева, количество чипом справа.
            const li = document.createElement('li');
            li.className = 'd-flex justify-content-between align-items-center gap-2 p-2 rounded-3 bg-body-tertiary';
            const name = document.createElement('span');
            name.className = 'fw-medium text-break';
            name.textContent = label;
            li.appendChild(name);
            if (qty) {
                const q = document.createElement('span');
                q.className = 'badge bg-body text-body-secondary fw-normal flex-shrink-0';
                q.textContent = qty;
                li.appendChild(q);
            }
            this.listTarget.appendChild(li);
        });
    }

    _modal() {
        return window.bootstrap.Modal.getOrCreateInstance(this.modalTarget);
    }

    // «Убрать позицию» (личная, вне нормы): с JS — подтверждение модалкой, затем POST скрытой exclude-формы
    // (исключает из учёта, история фактов сохраняется). Без JS сюда не попадаем — кнопка нативно сабмитит форму
    // (name=obligationKey), только без подтверждения.
    requestExclude(event) {
        event.preventDefault();
        this._excludeKey = event.currentTarget.dataset.key || '';
        this.excludeLabelTarget.textContent = event.currentTarget.dataset.label || '';
        this._excludeModalInstance().show();
    }

    confirmExclude() {
        this._excludeModalInstance().hide();
        const form = document.getElementById('exclude-obligation-form');
        if (!form) {
            return;
        }
        // requestSubmit() без сабмиттера не несёт name/value кнопки — кладём ключ явным скрытым полем.
        let input = form.querySelector('input[name="obligationKey"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'obligationKey';
            form.appendChild(input);
        }
        input.value = this._excludeKey || '';
        form.requestSubmit();
    }

    _excludeModalInstance() {
        return window.bootstrap.Modal.getOrCreateInstance(this.excludeModalTarget);
    }
}
