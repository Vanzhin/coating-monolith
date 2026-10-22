import { Controller } from '@hotwired/stimulus';
import { subscribe, unsubscribe } from '../push_subscription';

/**
 * Свитч web-push (PWA). Состояние свитча = есть ли активная подписка этого устройства. Вкл → subscribe(),
 * выкл → unsubscribe() (оба — общий модуль push_subscription, тот же unsubscribe зовёт и чистка логаута).
 * Если бэк не принял или разрешение не дали — свитч возвращается в фактическое состояние. Браузер без push
 * или с отклонённым разрешением — свитч прячем. Требует https/localhost + SW.
 */
export default class extends Controller {
    static targets = ['switch'];
    static values = { key: String };

    async connect() {
        this.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        if (!this.supported || '' === this.keyValue || 'denied' === Notification.permission) {
            this.element.hidden = true;

            return;
        }
        const registration = await navigator.serviceWorker.ready;
        this.switchTarget.checked = Boolean(await registration.pushManager.getSubscription());
    }

    async toggle() {
        if (!this.supported) {
            return;
        }
        const wantOn = this.switchTarget.checked;
        this.switchTarget.disabled = true;
        try {
            if (wantOn) {
                this.switchTarget.checked = await subscribe(this.keyValue);
            } else {
                await unsubscribe();
                this.switchTarget.checked = false;
            }
        } finally {
            this.switchTarget.disabled = false;
        }
    }
}
