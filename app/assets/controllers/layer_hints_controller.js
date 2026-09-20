import { Controller } from '@hotwired/stimulus';

/**
 * Инлайн-подсказки по слою нанесения. Живёт на строке слоя: читает материал (id) и замеры из полей
 * строки, дёргает /layer-hints и рисует мягкие предупреждения (ТСП/точка росы). Ничего не блокирует.
 */
export default class extends Controller {
    static targets = ['output'];
    static values = { url: { type: String, default: '' } };

    connect() {
        this._timer = null;
        this._seq = 0;
        this._handler = this._onInput.bind(this);
        this.element.addEventListener('input', this._handler);
        this._evaluate();
    }

    disconnect() {
        this.element.removeEventListener('input', this._handler);
        if (this._timer) clearTimeout(this._timer);
    }

    _onInput() {
        if (this._timer) clearTimeout(this._timer);
        this._timer = setTimeout(() => this._evaluate(), 400);
    }

    _val(suffix) {
        const el = this.element.querySelector(`[name$="[${suffix}]"]`);
        return el ? el.value.trim() : '';
    }

    async _evaluate() {
        const coatingId = this._val('material');
        // Точка росы считается по t воздуха + влажности и без выбранного материала;
        // предупреждения по порогам — только когда материал задан. Запрос имеет смысл, если есть хоть одно.
        const hasEnv = this._val('air_temp') !== '' && this._val('humidity') !== '';
        if (coatingId === '' && !hasEnv) {
            this._render([]);
            this._setDewPoint(null);
            return;
        }
        this._seq += 1;
        const seq = this._seq;

        const u = new URL(this.urlValue, window.location.origin);
        if (coatingId !== '') u.searchParams.set('coatingId', coatingId);
        const map = { surface_temp: 'surfaceTemp', air_temp: 'airTemp', humidity: 'humidity', mean: 'dryFilmMean', color: 'color' };
        Object.keys(map).forEach(k => {
            const v = this._val(k);
            if (v !== '') u.searchParams.set(map[k], v);
        });

        let items = [];
        let dewPoint = null;
        try {
            const r = await fetch(u, { credentials: 'same-origin' });
            if (r.ok) {
                const j = await r.json();
                const payload = (j && j.data !== undefined) ? j.data : j;
                items = Array.isArray(payload) ? payload : (Array.isArray(payload?.warnings) ? payload.warnings : []);
                dewPoint = (payload && typeof payload.dewPoint === 'number') ? payload.dewPoint : null;
            }
        } catch (err) {
            // сеть недоступна — подсказок просто нет
        }
        if (seq !== this._seq) return;
        this._setDewPoint(dewPoint);
        this._render(items);
    }

    /** Точка росы — производная (t воздуха + влажность); поле неактивно, значение считает бэк. */
    _setDewPoint(value) {
        const el = this.element.querySelector('[name$="[dew_point]"]');
        if (el) {
            el.value = value === null ? '' : String(value);
        }
    }

    _render(items) {
        if (!this.hasOutputTarget) return;
        this.outputTarget.innerHTML = items
            .map(w => `<div class="report-hint"><i class="bi bi-exclamation-triangle me-1"></i>${this._esc(w.message)}</div>`)
            .join('');
    }

    _esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
}
