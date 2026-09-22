import { Controller } from '@hotwired/stimulus';

/**
 * Стартовый экран PWA (/app). Публичная кэшируемая оболочка — открывается офлайн, инструменты доступны.
 * Онлайн залогиненного форвардим в кабинет клиентски: серверный редирект сделал бы страницу
 * некэшируемой и сломал бы офлайн-вход. Офлайн/аноним остаются на хабе.
 */
export default class extends Controller {
    static values = {
        authenticated: Boolean,
        cabinetUrl: String,
    };

    connect() {
        // replace (не assign) — чтобы «назад» не возвращало на /app в цикл редиректа.
        if (this.authenticatedValue && navigator.onLine && this.cabinetUrlValue) {
            window.location.replace(this.cabinetUrlValue);
        }
    }
}
