import { Controller } from '@hotwired/stimulus';

/**
 * Универсальный контроллер: submit'ит родительскую форму, когда на элементе
 * (обычно select) срабатывает change. Используется для sort-dropdown и
 * подобных «выбрал — форма ушла» UI-кейсов, чтобы не плодить одноразовые
 * инлайн-JS.
 *
 * data-controller="submit-on-change"
 * data-action="change->submit-on-change#submit"
 */
export default class extends Controller {
    submit(event) {
        const form = event.target.closest('form');
        if (form) {
            form.submit();
        }
    }
}
