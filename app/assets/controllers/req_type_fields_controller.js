import { Controller } from '@hotwired/stimulus';

/**
 * Тип требования (материальное/нематериальное) на уровне всей формы решает, показывать ли поля количества
 * у позиций. На создании тип выбирается радио-кнопками, на правке — залочен (hidden). Прячем/показываем
 * ячейки [data-item-quantity] у существующих строк И внутри <template> клонирования, чтобы новые строки
 * наследовали правильное состояние. Сервер всё равно игнорирует количество у нематериального типа.
 */
export default class extends Controller {
    static values = { requiresQuantity: Object };

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
        const need = this.requiresQuantityValue[this._currentType()] ?? true;

        this.element.querySelectorAll('[data-item-quantity]').forEach((cell) => { cell.hidden = !need; });
        this.element.querySelectorAll('template').forEach((tpl) => {
            tpl.content.querySelectorAll('[data-item-quantity]').forEach((cell) => { cell.hidden = !need; });
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
