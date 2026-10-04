import { Controller } from '@hotwired/stimulus';

/**
 * Простой редактор списка строк (нормы Compliance): «Добавить» клонирует <template>, заменяя `__i__`
 * на уникальный счётчик (для имён вида lines[__i__][field]); «Удалить» убирает строку. Индексы
 * уникальны, но не обязательно последовательны — сервер сжимает через array_values. Строки без
 * `__i__` (напр. positionIds[]) клонируются как есть. Вложенные Stimulus-контроллеры (typeahead)
 * подхватываются автоматически после вставки в живой DOM.
 */
export default class extends Controller {
    static targets = ['rows', 'template'];

    connect() {
        this._n = this.rowsTarget.querySelectorAll('[data-req-rows-target="row"]').length;
    }

    add() {
        const html = this.templateTarget.innerHTML.replaceAll('__i__', String(this._n++));
        this.rowsTarget.insertAdjacentHTML('beforeend', html);
    }

    remove(event) {
        event.target.closest('[data-req-rows-target="row"]')?.remove();
    }
}
