import { Controller } from '@hotwired/stimulus';

/*
 * Офлайн-индикатор. Висит на самом баннере (this.element): показывает его, когда пропала сеть,
 * и прячет при возврате. Источник правды — navigator.onLine + события window online/offline.
 * Баннер помечен атрибутом hidden в разметке — стартует скрытым, без вспышки при онлайне.
 *
 * Заодно отражает состояние сети классом `is-offline` на <html>: офлайн-страницы отдаются из
 * кэша анонимно, поэтому залогиненному показывают гостевую разметку (кнопка «Войти» и т.п.).
 * CSS `.is-offline .online-only { display:none }` гасит такие онлайн-онли элементы, когда сети
 * нет (войти офлайн всё равно нельзя). Первичную установку класса делает FOUC-safe-скрипт в head.
 */
export default class extends Controller {
    connect() {
        this.update = this.update.bind(this);
        window.addEventListener('online', this.update);
        window.addEventListener('offline', this.update);
        this.update();
    }

    disconnect() {
        window.removeEventListener('online', this.update);
        window.removeEventListener('offline', this.update);
    }

    update() {
        this.element.hidden = navigator.onLine;
        document.documentElement.classList.toggle('is-offline', !navigator.onLine);
    }
}
