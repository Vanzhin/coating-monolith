import { Controller } from '@hotwired/stimulus';

/**
 * Повторяемые строки списочного блока отчёта (приборы/дефекты/рекомендации/комиссия).
 * Клонирует <template> и держит имена вида content[block][items][N][sub] сплошными (0..n),
 * чтобы бэк получил список (array_is_list), а не разреженный массив после удаления строк.
 */
export default class extends Controller {
    static targets = ['rows', 'row', 'template'];

    add() {
        this.rowsTarget.appendChild(this.templateTarget.content.cloneNode(true));
        this.renumber();
    }

    remove(event) {
        const row = event.target.closest('[data-report-list-rows-target="row"]');
        if (row) {
            row.remove();
        }
        this.renumber();
    }

    renumber() {
        const rows = this.rowsTarget.querySelectorAll('[data-report-list-rows-target="row"]');
        rows.forEach((row, index) => {
            row.querySelectorAll('[name]').forEach((el) => {
                // Заменяем ТОЛЬКО индекс строки — скобку сразу после content[block][field].
                // Хвост (в т.ч. вложенные подполя [applied][from], [thinner][name]) не трогаем.
                el.name = el.name.replace(/^(content\[[^\]]*\]\[[^\]]*\])\[(?:\d+|__i__)\]/, `$1[${index}]`);
            });
        });
    }
}
