import { Controller } from '@hotwired/stimulus';

/**
 * Поля периода строки («Число» + «Единица периода») нужны только для вида «каждые N» (periodic). Делегируем
 * change по контейнеру строк — работает и для добавленных клонированием. Для остальных видов (однократно/по
 * факту/по документам изготовителя) сервер эти поля и так игнорирует; прячем их ради ясности.
 */
export default class extends Controller {
    connect() {
        this._onChange = this._onChange.bind(this);
        this.element.addEventListener('change', this._onChange);
        this.element.querySelectorAll('[data-cadence-kind]').forEach((select) => this._apply(select));
    }

    disconnect() {
        this.element.removeEventListener('change', this._onChange);
    }

    _onChange(event) {
        if (event.target.matches('[data-cadence-kind]')) {
            this._apply(event.target);
        }
    }

    _apply(select) {
        const row = select.closest('[data-req-rows-target="row"]');
        if (!row) {
            return;
        }
        const periodic = 'periodic' === select.value;
        row.querySelectorAll('[data-cadence-period]').forEach((cell) => { cell.hidden = !periodic; });
    }
}
