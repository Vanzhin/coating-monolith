import { Controller } from '@hotwired/stimulus';

/*
 * Прогрессивное подтверждение удаления (контроллер висит на <body>).
 *
 * Кнопка удаления — type=submit внутри POST-формы (см. _delete_form.html.twig):
 *  - без JS: клик сразу сабмитит форму (удаление работает, без модалки);
 *  - с JS: arm() перехватывает клик (preventDefault → форма НЕ сабмитится), Bootstrap
 *    по data-bs-toggle открывает общий #deleteModal; по кнопке «Удалить» confirm()
 *    сабмитит запомненную форму через requestSubmit() (минуя arm, т.к. это submit-событие).
 */
export default class extends Controller {
    arm(event) {
        event.preventDefault();
        this.form = event.currentTarget.closest('form');
        const title = event.currentTarget.getAttribute('data-bs-title') || '';
        const titleEl = document.querySelector('#deleteModal [data-role="title"]');
        if (titleEl) {
            titleEl.textContent = title ? `Удалить ${title}?` : 'Удалить?';
        }
    }

    confirm() {
        if (this.form) {
            this.form.requestSubmit();
        }
    }
}
