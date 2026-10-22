import { Controller } from '@hotwired/stimulus';
import { unsubscribe } from '../push_subscription';

/**
 * Чистка push при выходе. Перед уходом на /logout снимаем подписку ТЕКУЩЕГО устройства (общий
 * unsubscribe из push_subscription): разлогиненное устройство перестаёт получать уведомления, другие
 * устройства не трогаются. Отписку требуется сделать ДО навигации — эндпоинт отписки под /cabinet и
 * доступен только залогиненному. Всё best-effort и ограничено таймаутом, чтобы выход не залипал на
 * висящей сети или service worker'е, который не поднимается.
 */
export default class extends Controller {
    static values = { timeout: { type: Number, default: 1500 } };

    async run(event) {
        event.preventDefault();
        const href = this.element.href;
        try {
            await Promise.race([
                unsubscribe(),
                new Promise((resolve) => { setTimeout(resolve, this.timeoutValue); }),
            ]);
        } catch (e) {
            // best-effort: ошибка отписки не должна блокировать выход
        }
        window.location.href = href;
    }
}
