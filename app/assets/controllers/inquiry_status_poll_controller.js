import { Controller } from '@hotwired/stimulus';

/**
 * Поллинг статуса обрабатываемого запроса. Пока бэк отвечает processing=true — опрашиваем endpoint
 * каждые intervalValue мс; как только обработка завершилась — один location.reload() (сервер
 * перерисует карточку целиком: системы/параметры/ошибку). Авто-стоп через maxDurationValue — воркер
 * мог умереть, бесконечно долбить незачем (на карточке есть ссылка «Запустить заново»).
 *
 * Values:
 *   endpoint     — URL статус-эндпоинта, отдаёт {status, processing}.
 *   interval     — период опроса, мс (по умолчанию 15000).
 *   maxDuration  — предел поллинга, мс (по умолчанию 180000).
 */
export default class extends Controller {
    static values = {
        endpoint: String,
        interval: { type: Number, default: 15000 },
        maxDuration: { type: Number, default: 180000 },
    };

    connect() {
        this._elapsed = 0;
        this._timer = setInterval(() => this._tick(), this.intervalValue);
    }

    disconnect() {
        this._stop();
    }

    async _tick() {
        this._elapsed += this.intervalValue;
        if (this._elapsed >= this.maxDurationValue) {
            this._stop();
            return;
        }

        try {
            const res = await fetch(this.endpointValue, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                return; // 403/404/5xx — тихо ждём следующий тик, не спамим
            }
            const body = await res.json();
            const data = body.data ?? body; // снимаем общий JSON-конверт {result,status,data,message}
            if (data && false === data.processing) {
                this._stop();
                window.location.reload();
            }
        } catch {
            // сеть моргнула — ждём следующий тик
        }
    }

    _stop() {
        if (this._timer) {
            clearInterval(this._timer);
            this._timer = null;
        }
    }
}
