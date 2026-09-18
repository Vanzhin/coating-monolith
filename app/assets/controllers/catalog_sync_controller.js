import { Controller } from '@hotwired/stimulus';
import * as store from '../catalog_store.js';

/**
 * Синк офлайн-каталога покрытий: при загрузке страницы и возврате сети тянет каталог с сервера
 * условным запросом и полностью заменяет локальный стор при изменении.
 *
 * Сервер отдаёт version в ТЕЛЕ ({data:{items,version}} после конверта ResponseListener — заголовок
 * ETag конверт теряет), поэтому храним и шлём version в If-None-Match запроса; 304 без тела —
 * каталог не менялся, ничего не делаем.
 *
 *   <div data-controller="catalog-sync"
 *        data-catalog-sync-endpoint-value="{{ path('app_cabinet_coating_coating_catalog') }}"></div>
 */
export default class extends Controller {
    static values = { endpoint: String };

    connect() {
        this._onOnline = () => this.sync();
        window.addEventListener('online', this._onOnline);
        this.sync();
    }

    disconnect() {
        window.removeEventListener('online', this._onOnline);
    }

    async sync() {
        if (this._syncing || !navigator.onLine || !this.endpointValue) {
            return;
        }

        this._syncing = true;
        try {
            const version = await store.getVersion();
            const res = await fetch(this.endpointValue, {
                headers: version ? { 'If-None-Match': version } : {},
                credentials: 'same-origin',
            });

            if (res.status === 200) {
                const json = await res.json();
                const payload = json.data ?? json; // снимаем конверт {result,status,data,message}
                await store.replaceAll(payload.items ?? [], payload.version ?? null);
            }
            // 304 (не менялось) и сетевые ошибки — ничего не делаем: офлайн это норма
        } catch {
            // офлайн — старый кеш остаётся валидным
        } finally {
            this._syncing = false;
        }
    }
}
