// Общие операции web-push подписки ТЕКУЩЕГО устройства. Ровно два действия:
//  - subscribe(key): спросить разрешение → подписать браузер → создать WEB_PUSH-канал на бэке.
//  - unsubscribe():  снять подписку именно этого устройства (канал по endpoint + браузерный unsubscribe).
// Переиспользуются свитчем настроек (push_subscribe_controller) и чисткой при логауте (logout_controller),
// чтобы логика подписки не дублировалась между ними.

const SUPPORTED = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

// Экспортируется для свитча (connect), чтобы не дублировать лукап. getRegistration() резолвится
// сразу — в отличие от navigator.serviceWorker.ready, который ВИСИТ, пока нет активного воркера
// (нет регистрации / Safari private). Иначе логаут впустую ждал бы весь таймаут, когда подписки нет.
export async function currentSubscription() {
    if (!SUPPORTED) {
        return null;
    }
    const registration = await navigator.serviceWorker.getRegistration();

    return registration ? registration.pushManager.getSubscription() : null;
}

/** VAPID public key (base64url) → Uint8Array для applicationServerKey. */
function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(normalized);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        out[i] = raw.charCodeAt(i);
    }

    return out;
}

/**
 * Подписать текущее устройство. Возвращает true, если подписка активна после вызова, иначе false
 * (нет поддержки/ключа, разрешение не дали или бэк не принял — локальную подписку откатываем, чтобы
 * браузер и сервер не разъезжались).
 */
export async function subscribe(key) {
    if (!SUPPORTED || '' === key) {
        return false;
    }
    if (await currentSubscription()) {
        return true; // уже включено
    }
    if ('granted' !== await Notification.requestPermission()) {
        return false; // разрешение не дали
    }
    let subscription = null;
    try {
        const registration = await navigator.serviceWorker.ready;
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(key),
        });
        const res = await fetch('/cabinet/push/subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(subscription),
        });
        if (!res.ok || res.redirected) {
            throw new Error('subscribe rejected');
        }

        return true;
    } catch (e) {
        if (subscription) {
            try {
                await subscription.unsubscribe();
            } catch (_) {
                // best-effort
            }
        }

        return false;
    }
}

/**
 * Отписать текущее устройство: шлём бэку endpoint текущей подписки (он удаляет ровно её канал,
 * подписки других устройств живут), затем снимаем подписку в браузере. Безопасно к вызову где угодно:
 * нет поддержки или подписки — тихий no-op. POST — best-effort: даже если не дошёл, браузерная отписка
 * убирает устройство как адресата, а осиротевший канал бэк снесёт лениво по 410 при следующей отправке.
 */
export async function unsubscribe() {
    if (!SUPPORTED) {
        return;
    }
    const existing = await currentSubscription();
    if (!existing) {
        return; // на этом устройстве подписки нет — снимать нечего
    }
    try {
        await fetch('/cabinet/push/unsubscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ endpoint: existing.endpoint }),
        });
    } catch (e) {
        // не критично — см. комментарий выше
    }
    try {
        await existing.unsubscribe();
    } catch (_) {
        // best-effort: даже если браузер не снял подписку, канал уйдёт лениво по 410
    }
}
