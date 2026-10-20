import { Controller } from '@hotwired/stimulus';

/**
 * Простой редактор списка строк (нормы Compliance): «Добавить» клонирует <template>, заменяя `__i__`
 * на уникальный счётчик (для имён вида lines[__i__][field]); «Удалить» убирает строку. Индексы
 * уникальны, но не обязательно последовательны — сервер сжимает через array_values. Строки без
 * `__i__` (напр. positionIds[]) клонируются как есть. Вложенные Stimulus-контроллеры (typeahead)
 * подхватываются автоматически после вставки в живой DOM.
 *
 * `restore` — позиция нормы, мягко убранная ранее (надгробие, рисуется disabled+замком, см.
 * admin/compliance/requirements/_macros.html.twig item_row): снимает disabled с её полей, чтобы на
 * сохранении форма прислала её id вместе с данными — Requirement::mergeItems сам вернёт позицию в
 * активные (это и есть восстановление, доменных изменений не требует). Трэш-кнопку назад не ставим
 * (дубль разметки с сервером) — после сохранения/перезагрузки позиция рендерится обычной активной строкой.
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

    requestRemove(event) {
        this._pendingRow = event.target.closest('[data-req-rows-target="row"]');
    }

    confirmRemove() {
        this._pendingRow?.remove();
        this._pendingRow = null;
    }

    restore(event) {
        const row = event.target.closest('[data-req-rows-target="row"]');
        if (!row) {
            return;
        }
        row.querySelectorAll('[disabled]').forEach((el) => el.removeAttribute('disabled'));
        row.classList.remove('opacity-75');
        row.removeAttribute('data-removed');
        row.querySelector('[data-removed-marker]')?.remove();
        event.target.closest('button')?.remove();
    }
}
