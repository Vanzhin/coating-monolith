import { Controller } from '@hotwired/stimulus';

/**
 * Двух-ручковый range slider с видимыми number-инпутами. Реализация без
 * зависимостей: два накладываемых <input type="range"> для перетаскивания
 * ручек + два <input type="number"> для видимого/редактируемого значения.
 *
 * Источник истины для submit'a — number-инпуты (у них правильный name).
 * Range'ы — только UI-viz, синхронизируются в обе стороны.
 *
 * Инварианты:
 *  - drag range → пишем ТОЛЬКО в тот number-инпут, чья ручка двигалась;
 *    противоположный трогаем только при push-коллизии (from > to).
 *    Иначе одноручковый drag «материализовал» бы intended-empty границу.
 *  - typeInput не тянет ручки на промежуточные NaN-состояния (например,
 *    после первого '-' в '-30'), чтобы фиксом не бегала заливка.
 *  - blur нормализует только порядок (from ≤ to), НЕ clamp'ит значения
 *    к min/max: URL может исторически хранить значение вне slider bounds,
 *    и молча его переписывать нельзя. Bounds валидирует бэк.
 *  - reset() публично сбрасывает state (значения и CSS-переменные заливки),
 *    вызывается flip_card_controller при переворачивании на невидимую сторону.
 *  - Когда обе ручки стакаются (from==to), toggle'им CSS-класс,
 *    меняющий z-index приоритет — пользователь может кликнуть ещё раз и
 *    попасть уже в противоположную ручку.
 */
export default class extends Controller {
    static values = {
        min: Number,
        max: Number,
        step: { type: Number, default: 1 },
    };
    static targets = ['fromInput', 'toInput', 'fromRange', 'toRange'];

    connect() {
        this._syncRangesFromInputs();
        this._updateFill();
    }

    dragRange(event) {
        const draggedFrom = event.target === this.fromRangeTarget;
        let from = Number(this.fromRangeTarget.value);
        let to = Number(this.toRangeTarget.value);

        if (from > to) {
            if (draggedFrom) {
                this.toRangeTarget.value = String(from);
                this.toInputTarget.value = String(from);
                to = from;
            } else {
                this.fromRangeTarget.value = String(to);
                this.fromInputTarget.value = String(to);
                from = to;
            }
        }

        // Пишем ТОЛЬКО ту сторону, что двигалась. Обратная — не наша забота
        // (мы её задели выше только при push-коллизии).
        if (draggedFrom) {
            this.fromInputTarget.value = String(from);
        } else {
            this.toInputTarget.value = String(to);
        }

        this._updateStackedPriority(from === to);
        this._updateFill();
    }

    typeInput(event) {
        // Не трогаем range'ы на промежуточных нечисловых состояниях (например,
        // '-' в процессе набора '-30'). Иначе thumb'ы прыгают в min/max.
        const raw = event.target.value;
        const parsed = this._numberOrNull(raw);
        if (raw !== '' && parsed === null) {
            return;
        }
        this._syncRangesFromInputs();
        this._updateFill();
    }

    /** blur нормализует только порядок from ≤ to. Bounds — забота бэка. */
    blurInput() {
        const from = this._numberOrNull(this.fromInputTarget.value);
        const to = this._numberOrNull(this.toInputTarget.value);

        if (from !== null && to !== null && from > to) {
            this.fromInputTarget.value = String(to);
            this.toInputTarget.value = String(from);
        }

        this._syncRangesFromInputs();
        this._updateFill();
    }

    /**
     * Публичный reset: очищает значения number-инпутов и пересчитывает
     * CSS-переменные заливки. Вызывается flip_card_controller при
     * переворачивании стороны с этим слайдером на невидимую.
     */
    reset() {
        this.fromInputTarget.value = '';
        this.toInputTarget.value = '';
        this._syncRangesFromInputs();
        this._updateFill();
        this._updateStackedPriority(false);
    }

    _syncRangesFromInputs() {
        const from = this._numberOrNull(this.fromInputTarget.value);
        const to = this._numberOrNull(this.toInputTarget.value);
        this.fromRangeTarget.value = String(from ?? this.minValue);
        this.toRangeTarget.value = String(to ?? this.maxValue);
    }

    _updateFill() {
        const span = this.maxValue - this.minValue;
        if (span <= 0) return;
        const fromPct = ((Number(this.fromRangeTarget.value) - this.minValue) / span) * 100;
        const toPct = ((Number(this.toRangeTarget.value) - this.minValue) / span) * 100;
        this.element.style.setProperty('--range-slider-from', fromPct + '%');
        this.element.style.setProperty('--range-slider-to', toPct + '%');
    }

    /**
     * Когда thumb'ы стакаются, toggle'им класс, меняющий CSS z-index
     * приоритет — следующий клик пойдёт в противоположную ручку.
     */
    _updateStackedPriority(stacked) {
        if (!stacked) {
            this.element.classList.remove('range-slider-flip-priority');
            return;
        }
        this.element.classList.toggle('range-slider-flip-priority');
    }

    _numberOrNull(raw) {
        if (raw === '' || raw === null || raw === undefined) return null;
        const n = Number(raw);
        return Number.isFinite(n) ? n : null;
    }
}
