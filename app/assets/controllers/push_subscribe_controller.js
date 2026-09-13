import { Controller } from '@hotwired/stimulus';

/**
 * Подписка на web-push (PWA). По клику: запрос разрешения → pushManager.subscribe → POST подписки
 * на бэк (создаёт WEB_PUSH-канал). Если уже подписан — выключает. Требует https/localhost + SW.
 * Браузер без поддержки push или с отклонённым разрешением — кнопку прячем.
 */
export default class extends Controller {
    static targets = ['button', 'label'];
    static values = { key: String };

    async connect() {
        this.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        if (!this.supported || '' === this.keyValue || 'denied' === Notification.permission) {
            this.element.hidden = true;

            return;
        }
        this.setLabel((await this.currentSubscription()) ? 'Уведомления включены' : 'Включить уведомления');
    }

    async toggle() {
        if (!this.supported) {
            return;
        }
        this.buttonTarget.disabled = true;
        try {
            const existing = await this.currentSubscription();
            if (existing) {
                await existing.unsubscribe();
                this.setLabel('Включить уведомления');

                return;
            }
            if ('granted' !== await Notification.requestPermission()) {
                return;
            }
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.keyValue),
            });
            // Успех только если бэк реально принял подписку. Иначе (422/403/500 или
            // редирект на логин при протухшей сессии) откатываем локальную подписку,
            // чтобы браузер и сервер не разъехались, и не врём «включено».
            try {
                const res = await fetch('/cabinet/push/subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(subscription),
                });
                if (!res.ok || res.redirected) {
                    throw new Error('subscribe rejected');
                }
                this.setLabel('Уведомления включены');
            } catch (e) {
                await subscription.unsubscribe();
                this.setLabel('Не удалось включить, попробуйте позже');
            }
        } finally {
            this.buttonTarget.disabled = false;
        }
    }

    async currentSubscription() {
        const registration = await navigator.serviceWorker.ready;

        return registration.pushManager.getSubscription();
    }

    setLabel(text) {
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = text;
        }
    }

    /** VAPID public key (base64url) → Uint8Array для applicationServerKey. */
    urlBase64ToUint8Array(base64) {
        const padding = '='.repeat((4 - (base64.length % 4)) % 4);
        const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(normalized);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }

        return out;
    }
}
