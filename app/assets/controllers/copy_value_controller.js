import { Controller } from '@hotwired/stimulus';

/**
 * Копирование расчётного значения калькулятора в буфер по кнопке. Ставится на ряд:
 *   source — поле/спан со значением, icon — иконка кнопки (на 1.5с меняется на галочку-подтверждение).
 * Пусто или «—» не копируем.
 */
export default class extends Controller {
    static targets = ['source', 'icon'];

    async copy() {
        if (!this.hasSourceTarget) {
            return;
        }
        const el = this.sourceTarget;
        const raw = 'value' in el ? el.value : (el.textContent || '');
        const text = raw.trim();
        if ('' === text || '—' === text) {
            return;
        }
        try {
            await navigator.clipboard.writeText(text);
            this._markCopied();
        } catch (e) {
            // буфер недоступен (нет https/разрешения) — тихо
        }
    }

    /**
     * Галочка остаётся (без авто-возврата), и одна за раз: сбрасываем все копи-иконки этого
     * калькулятора на «копировать», текущую — на галочку. Копируешь другую → галочка уходит к ней.
     */
    _markCopied() {
        if (!this.hasIconTarget) {
            return;
        }
        const scope = this.element.closest('.film-card, .mix-card') || document;
        scope.querySelectorAll('.calc-copy i').forEach((i) => { i.className = 'bi bi-copy'; });
        this.iconTarget.className = 'bi bi-check-lg';
    }
}
