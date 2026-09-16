import { Controller } from '@hotwired/stimulus';

/*
 * Живое обновление уведомлений без перезагрузки. Service worker при получении пуша шлёт открытым
 * вкладкам сообщение { type:'notification', unread:N }. По нему:
 *   - обновляем число на колоколе (badge-таргеты — во всех местах разом);
 *   - если открыта страница списка (list-таргет) — сами перезапрашиваем 1-ю страницу партиалом
 *     (?partial=1) и перерисовываем содержимое, без перезагрузки.
 * Висит на <body>, поэтому таргеты ловятся на любой странице оболочки.
 */
export default class extends Controller {
    static targets = ['badge', 'list'];

    connect() {
        // На странице списка (ListAction уже пометил всё прочитанным) гасим бейдж на иконке PWA —
        // это единственное место «увидел», согласованное с серверной пометкой. НЕ на каждой навигации.
        if (this.hasListTarget && navigator.clearAppBadge) {
            navigator.clearAppBadge().catch(() => { /* бейдж не критичен */ });
        }
        if (!('serviceWorker' in navigator)) {
            return;
        }
        this.onMessage = this.onMessage.bind(this);
        navigator.serviceWorker.addEventListener('message', this.onMessage);
    }

    disconnect() {
        if (this.onMessage) {
            navigator.serviceWorker.removeEventListener('message', this.onMessage);
        }
    }

    onMessage(event) {
        const data = event.data || {};
        if ('notification' !== data.type) {
            return;
        }
        if ('number' === typeof data.unread) {
            this.updateBadges(data.unread);
        }
        this.refreshList();
    }

    updateBadges(unread) {
        this.badgeTargets.forEach((el) => {
            el.textContent = unread;
            el.classList.toggle('d-none', unread <= 0);
        });
    }

    async refreshList() {
        if (!this.hasListTarget) {
            return;
        }
        const list = this.listTarget.querySelector('[data-controller~="infinite-list"]');
        const base = list ? list.getAttribute('data-infinite-list-base-url-value') : '';
        if (!base) {
            return;
        }
        // Частые пуши подряд: отменяем предыдущий незавершённый запрос, чтобы устаревший ответ
        // не затёр более свежий (out-of-order).
        if (this.refreshAbort) {
            this.refreshAbort.abort();
        }
        const ctrl = new AbortController();
        this.refreshAbort = ctrl;
        try {
            const sep = base.includes('?') ? '&' : '?';
            // Тянем весь блок (обёртка + кнопка «Показать ещё»), а не только карточки: сервер
            // пересчитывает номера страниц, поэтому кнопка не теряется при росте списка за порог.
            const resp = await fetch(`${base}${sep}fragment=list`, { headers: { Accept: 'text/html' }, signal: ctrl.signal });
            if (!resp.ok) {
                return;
            }
            // Замена всего блока переинициализирует infinite-list (Stimulus подключит новый инстанс).
            this.listTarget.innerHTML = (await resp.text()).trim();
        } catch (e) {
            // AbortError (устаревший запрос) или сеть — не критично, юзер может обновить страницу
        }
    }
}
