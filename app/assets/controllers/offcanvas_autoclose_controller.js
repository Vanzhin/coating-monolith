import { Controller } from '@hotwired/stimulus';

/*
 * Закрывает offcanvas при клике по пункту-ссылке навигации — иначе пользователь
 * выбрал раздел и остался с открытой шторкой. Контроллер вешается на сам
 * offcanvas (#mainMenu), close() зовётся с пунктов через data-action.
 * Перенесено из inline-<script> base.html.twig.
 */
export default class extends Controller {
    close() {
        window.bootstrap?.Offcanvas.getInstance(this.element)?.hide();
    }
}
