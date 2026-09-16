import { Controller } from '@hotwired/stimulus';

/**
 * Тоггл web-push (PWA). Состояние свитча = есть ли активная подписка. Вкл → запрос разрешения →
 * pushManager.subscribe → POST на бэк (создаёт WEB_PUSH-канал). Выкл → unsubscribe. Если бэк не
 * принял (или разрешение не дали) — откатываем локальную подписку и сам свитч. Браузер без push
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
        this.switchTarget.checked = Boolean(await this.currentSubscription());
    }

    async toggle() {
        if (!this.supported) {
            return;
        }
        const wantOn = this.switchTarget.checked;
        this.switchTarget.disabled = true;
        try {
            if (wantOn) {
                await this.enable();
            } else {
                await this.disable();
            }
        } finally {
            this.switchTarget.disabled = false;
        }
    }

    async enable() {
        if (await this.currentSubscription()) {
            return; // уже включено
        }
        if ('granted' !== await Notification.requestPermission()) {
            this.switchTarget.checked = false; // разрешение не дали — откат тумблера

            return;
        }
        let subscription = null;
        try {
            const registration = await navigator.serviceWorker.ready;
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8Array(this.keyValue),
            });
            const res = await fetch('/cabinet/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(subscription),
            });
            if (!res.ok || res.redirected) {
                throw new Error('subscribe rejected');
            }
        } catch (e) {
            // subscribe() упал или бэк не принял — откатываем локальную подписку и тумблер, чтобы
            // браузер и сервер не разъезжались, а промис не улетал необработанным.
            if (subscription) {
                try {
                    await subscription.unsubscribe();
                } catch (_) {
                    // best-effort
                }
            }
            this.switchTarget.checked = false;
        }
    }

    async disable() {
        // Выключение = выкл на всех устройствах: просим бэк удалить ВСЕ WEB_PUSH-каналы юзера,
        // затем отписываем локальный браузер. Порядок: сначала сервер (чтобы запись точно ушла),
        // потом браузер — оба best-effort, чтобы не разъезжались.
        try {
            await fetch('/cabinet/push/unsubscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (e) {
            // не критично — попробуем ещё раз при следующем выключении
        }
        const existing = await this.currentSubscription();
        if (existing) {
            await existing.unsubscribe();
        }
    }

    async currentSubscription() {
        const registration = await navigator.serviceWorker.ready;

        return registration.pushManager.getSubscription();
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
