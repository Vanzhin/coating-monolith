import { Controller } from '@hotwired/stimulus';

/*
 * Кнопка «Наверх». Контроллер вешается на <body>; кнопка — target 'button'
 * (её показываем после 300px скролла), клик по кнопке и по футер-ссылке
 * «Наверх» зовёт top(). Логика перенесена из inline-<script> base.html.twig.
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.onScroll = this.onScroll.bind(this);
        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.onScroll();
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
    }

    onScroll() {
        if (this.hasButtonTarget) {
            this.buttonTarget.style.display = window.scrollY > 300 ? 'flex' : 'none';
        }
    }

    top(event) {
        if (event) event.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}
