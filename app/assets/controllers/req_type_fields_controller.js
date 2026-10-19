import { Controller } from '@hotwired/stimulus';

/**
 * Тип требования на уровне всей формы решает ТРИ НЕЗАВИСИМЫЕ вещи: показывать ли поля количества у позиций
 * (requiresQuantity — сейчас true у обоих типов), показывать ли селектор вида журнала (carriesJournal —
 * только у периодического, инструктаж/процедура) и показывать ли селектор вида ПОЗИЦИИ (allowsMixed —
 * только у «Выдачи»: там инвентарь и расходник соседствуют в одной карточке, «Периодическая» однородна).
 * На создании тип выбирается радио-кнопками, на правке — залочен (hidden). Прячем/показываем ячейки
 * [data-item-quantity]/[data-item-type] у существующих строк И внутри <template> клонирования, чтобы новые
 * строки наследовали правильное состояние. Сервер всё равно гасит журнал у материального, игнорирует
 * количество там, где оно не требуется, и подставляет тип требования позиции без явного типа.
 */
export default class extends Controller {
    static values = { requiresQuantity: Object, carriesJournal: Object, allowsMixed: Object };

    connect() {
        this._onChange = this._onChange.bind(this);
        this.element.addEventListener('change', this._onChange);
        this._apply();
    }

    disconnect() {
        this.element.removeEventListener('change', this._onChange);
    }

    _onChange(event) {
        if (event.target.matches('input[name="type"]')) {
            this._apply();
        }
    }

    _apply() {
        const type = this._currentType();
        const need = this.requiresQuantityValue[type] ?? true;
        const journal = this.carriesJournalValue[type] ?? false;
        const mixed = this.allowsMixedValue[type] ?? false;

        this.element.querySelectorAll('[data-item-quantity]').forEach((cell) => { cell.hidden = !need; });
        // Селектор вида журнала — показываем только у типа, который журнал несёт (периодическое).
        this.element.querySelectorAll('[data-non-material-only]').forEach((el) => { el.hidden = !journal; });
        // Селектор вида позиции — показываем только у типа, который держит разные виды позиций вместе («Выдача»).
        this.element.querySelectorAll('[data-item-type]').forEach((cell) => { cell.hidden = !mixed; });
        this.element.querySelectorAll('template').forEach((tpl) => {
            tpl.content.querySelectorAll('[data-item-quantity]').forEach((cell) => { cell.hidden = !need; });
            tpl.content.querySelectorAll('[data-item-type]').forEach((cell) => { cell.hidden = !mixed; });
        });
    }

    _currentType() {
        const checked = this.element.querySelector('input[name="type"]:checked');
        if (checked) {
            return checked.value;
        }
        const hidden = this.element.querySelector('input[name="type"]');

        return hidden ? hidden.value : '';
    }
}
