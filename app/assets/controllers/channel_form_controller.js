import { Controller } from '@hotwired/stimulus';

/**
 * Форма создания канала: по типу канала подсказывает формат поля значения
 * (email / telegram) — тип input, placeholder и help-текст. Заменяет прежние
 * inline onchange/DOMContentLoaded-скрипты в шаблоне.
 */
export default class extends Controller {
    static targets = ['type', 'value', 'help'];

    static HINTS = {
        email: { type: 'email', placeholder: 'example@domain.com', help: 'Введите адрес электронной почты' },
        telegram: { type: 'text', placeholder: '@username или user_id', help: 'Введите Telegram username (с @) или user_id' },
    };

    connect() {
        if (this.hasTypeTarget && this.typeTarget.value) {
            this._apply(this.typeTarget.value);
        }
    }

    onTypeChange() {
        this._apply(this.typeTarget.value);
    }

    _apply(type) {
        const hint = this.constructor.HINTS[type] || {
            type: 'text', placeholder: 'Введите значение канала', help: 'Выберите тип канала для подсказки',
        };
        if (this.hasValueTarget) {
            this.valueTarget.type = hint.type;
            this.valueTarget.placeholder = hint.placeholder;
        }
        if (this.hasHelpTarget) {
            this.helpTarget.textContent = hint.help;
        }
    }
}
