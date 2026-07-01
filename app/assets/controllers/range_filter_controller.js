import { Controller } from '@hotwired/stimulus';
import noUiSlider from 'nouislider';
import 'nouislider/dist/nouislider.css';

/**
 * Range-фасет фильтра списка покрытий. Работает в двух режимах:
 *  - со слайдером (target='slider' присутствует) — dual-range noUiSlider
 *    плюс кнопки-пресеты, которые двигают ползунок.
 *  - только чипы (без slider target) — кнопки-пресеты напрямую ставят
 *    hidden from/to и submit'ят форму.
 *
 * В обоих режимах источник истины — два hidden input'а (fromInput/toInput),
 * их и читает backend.
 *
 * Auto-submit: любое изменение (drag-end слайдера, клик по чипу или reset)
 * → форма отправляется, страница перезагружается с новым фильтром.
 */
export default class extends Controller {
    static targets = ['slider', 'fromInput', 'toInput'];
    static values = {
        min: Number,
        max: Number,
    };

    connect() {
        if (this.hasSliderTarget) {
            const fromRaw = this.fromInputTarget.value;
            const toRaw = this.toInputTarget.value;
            const start = [
                fromRaw !== '' ? parseInt(fromRaw, 10) : this.minValue,
                toRaw   !== '' ? parseInt(toRaw, 10)   : this.maxValue,
            ];

            noUiSlider.create(this.sliderTarget, {
                start,
                connect: true,
                step: 1,
                range: { min: this.minValue, max: this.maxValue },
                tooltips: [true, true],
                format: {
                    to: (v) => Math.round(v),
                    from: (v) => Number(v),
                },
            });

            // 'end' — на mouseup/touchend, не на каждый пиксель drag'a.
            this.sliderTarget.noUiSlider.on('end', (values) => {
                const [from, to] = values.map((v) => parseInt(v, 10));
                this._applyRange(from, to);
            });
        }
    }

    disconnect() {
        if (this.hasSliderTarget && this.sliderTarget.noUiSlider) {
            this.sliderTarget.noUiSlider.destroy();
        }
    }

    preset(event) {
        const btn = event.currentTarget;
        const from = parseInt(btn.dataset.from, 10);
        const to = parseInt(btn.dataset.to, 10);
        this._applyRange(from, to);
    }

    reset() {
        if (this.hasSliderTarget && this.sliderTarget.noUiSlider) {
            this.sliderTarget.noUiSlider.set([this.minValue, this.maxValue]);
        }
        this.fromInputTarget.value = '';
        this.toInputTarget.value = '';
        this._submit();
    }

    _applyRange(from, to) {
        if (this.hasSliderTarget && this.sliderTarget.noUiSlider) {
            this.sliderTarget.noUiSlider.set([from, to]);
        }
        this.fromInputTarget.value = from;
        this.toInputTarget.value = to;
        this._submit();
    }

    _submit() {
        const form = this.element.closest('form');
        if (form) form.submit();
    }
}
