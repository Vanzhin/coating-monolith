import { Controller } from '@hotwired/stimulus';

/*
 * Офлайн-индикатор. Висит на самом баннере (this.element): показывает его, когда пропала сеть,
 * и прячет при возврате. Источник правды — navigator.onLine + события window online/offline.
 * Баннер помечен атрибутом hidden в разметке — стартует скрытым, без вспышки при онлайне.
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
    }
}
