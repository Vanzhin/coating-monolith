import { Controller } from '@hotwired/stimulus';

/**
 * Калькулятор расхода краски. Считает в браузере (офлайн). Формулы — те же, что в доменном
 * PaintConsumptionCalculator (источник истины):
 *   расход (л/м²) = DFT/(VS×10) × коэффициент;  коэффициент = 1/(1 − потери/100).
 * Разбавление на расход не влияет. Потери % ⇄ коэффициент связаны в обе стороны. Площадь ⇄ всего —
 * опорная та, куда ввели. Единицы объём/масса переключаются; масса = объём × плотность. Вёдра
 * (ceil) — при заданной фасовке. Всё вводится вручную (работает без входа); выбор покрытия просто
 * подставляет сухой остаток/плотность/фасовку. Внутри считаем в литрах, показываем в выбранной единице.
 */
export default class extends Controller {
    static targets = ['vs', 'dft', 'loss', 'coefficient', 'density', 'pack', 'area', 'total',
        'rate', 'rateUnit', 'totalUnit', 'packsRow', 'packs', 'packsNote', 'coatingMsg'];

    connect() {
        this.anchor = null;    // 'area' | 'total'
        this.unit = 'volume';  // 'volume' | 'mass'
        this.recompute();
    }

    onInput(event) {
        const el = event.target;
        this.sanitize(el);
        if (el === this.lossTarget) {
            this.syncCoefficientFromLoss();
        } else if (el === this.coefficientTarget) {
            this.syncLossFromCoefficient();
        } else if (el === this.areaTarget) {
            this.anchor = 'area';
        } else if (el === this.totalTarget) {
            this.anchor = 'total';
        }
        this.recompute();
    }

    /** Переключение Объём/Масса: пересохраняем «всего» в новой единице, чтобы величина не менялась. */
    changeUnit(event) {
        const newUnit = event.target.value;
        if ('total' === this.anchor && '' !== this.totalTarget.value.trim()) {
            const liters = this.toLiters(this.toNumber(this.totalTarget.value)); // старая единица
            this.unit = newUnit;
            const display = this.fromLiters(liters);
            this.totalTarget.value = (liters > 0 && null !== display) ? this.format(display, 2) : '';
        } else {
            this.unit = newUnit;
        }
        this.recompute();
    }

    // ── Потери ⇄ коэффициент ──
    syncCoefficientFromLoss() {
        if ('' === this.lossTarget.value.trim()) {
            this.coefficientTarget.value = '';

            return;
        }
        const loss = this.lossNumber();
        this.coefficientTarget.value = loss < 100 ? this.format(1 / (1 - loss / 100), 2) : '';
    }

    syncLossFromCoefficient() {
        if ('' === this.coefficientTarget.value.trim()) {
            this.lossTarget.value = '';

            return;
        }
        const coef = this.toNumber(this.coefficientTarget.value);
        this.lossTarget.value = coef >= 1 ? this.format((1 - 1 / coef) * 100, 1) : '';
    }

    recompute() {
        const vs = this.toNumber(this.vsTarget.value);
        const dft = this.toNumber(this.dftTarget.value);
        const loss = this.lossNumber();
        const coefficient = loss < 100 ? 1 / (1 - loss / 100) : null;
        const rate = (vs > 0 && dft > 0 && null !== coefficient) ? (dft / (vs * 10)) * coefficient : null; // л/м²

        this.relabelUnits();
        const rateDisplay = null !== rate ? this.fromLiters(rate) : null;
        this.rateTarget.textContent = null !== rateDisplay ? this.format(rateDisplay, 3) : '—';

        this.areaTarget.classList.toggle('is-anchor', 'area' === this.anchor);
        this.totalTarget.classList.toggle('is-anchor', 'total' === this.anchor);

        const liters = this.recomputeAreaTotal(rate);
        this.updatePacks(liters);
    }

    /** Пара площадь ⇄ всего от опорного поля. rate — л/м² (литры). Возвращает объём (л) или null. */
    recomputeAreaTotal(rate) {
        if (null === this.anchor) {
            return null;
        }
        const computed = 'area' === this.anchor ? this.totalTarget : this.areaTarget;
        if (null === rate) {
            computed.value = '';

            return null;
        }
        if ('area' === this.anchor) {
            const area = this.toNumber(this.areaTarget.value);
            const liters = area > 0 ? rate * area : null;
            const display = null !== liters ? this.fromLiters(liters) : null;
            this.totalTarget.value = null !== display ? this.format(display, 2) : '';

            return liters;
        }
        const liters = this.toLiters(this.toNumber(this.totalTarget.value));
        this.areaTarget.value = liters > 0 ? this.format(liters / rate, 1) : '';

        return liters > 0 ? liters : null;
    }

    updatePacks(liters) {
        const pack = this.toNumber(this.packTarget.value);
        if (pack > 0 && null !== liters && liters > 0) {
            const packs = Math.ceil(liters / pack);
            this.packsTarget.textContent = String(packs);
            this.packsNoteTarget.textContent = `${this.format(liters, 1)} л ÷ ${this.format(pack, 1)} л → ${packs} (округление вверх)`;
            this.packsRowTarget.hidden = false;
            this.packsNoteTarget.hidden = false;
        } else {
            this.packsRowTarget.hidden = true;
            this.packsNoteTarget.hidden = true;
        }
    }

    // ── Единицы ──
    relabelUnits() {
        this.rateUnitTarget.textContent = 'mass' === this.unit ? 'кг/м²' : 'л/м²';
        this.totalUnitTarget.textContent = 'mass' === this.unit ? 'кг' : 'л';
    }

    densityValue() {
        const n = parseFloat(this.densityTarget.value);

        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    /** Отображаемое → литры (в массе делим на плотность; без плотности — 0). */
    toLiters(value) {
        if ('mass' !== this.unit) {
            return value;
        }
        const d = this.densityValue();

        return d > 0 ? value / d : 0;
    }

    /** Литры → отображаемое (в массе ×плотность; без плотности — null «неизвестно»). */
    fromLiters(liters) {
        if ('mass' !== this.unit) {
            return liters;
        }
        const d = this.densityValue();

        return d > 0 ? liters * d : null;
    }

    // ── Подстановка из покрытия (автозаполнение; всё редактируемо после «убрать покрытие») ──
    applyFromCoating(event) {
        const item = event.detail?.item ?? {};
        this.setFromCoating(this.vsTarget, item.volumeSolid);
        this.setFromCoating(this.densityTarget, item.massDensity);
        this.setFromCoating(this.packTarget, item.pack);
        const hasVs = 'number' === typeof item.volumeSolid && item.volumeSolid > 0;
        this.showMsg(hasVs ? null : 'У этого покрытия нет данных о сухом остатке — введите вручную.');
        this.recompute();
    }

    setFromCoating(el, value) {
        if ('number' === typeof value && value > 0) {
            el.value = String(value);
            el.readOnly = true;
            el.classList.add('film-locked');
        }
    }

    clearCoating() {
        [this.vsTarget, this.densityTarget, this.packTarget].forEach((el) => {
            el.readOnly = false;
            el.classList.remove('film-locked');
        });
        this.showMsg(null);
        this.recompute();
    }

    showMsg(text) {
        if (!this.hasCoatingMsgTarget) {
            return;
        }
        this.coatingMsgTarget.textContent = text ?? '';
        this.coatingMsgTarget.hidden = !text;
    }

    lossNumber() {
        const raw = this.lossTarget.value.trim();
        if ('' === raw) {
            return 0;
        }
        const n = parseFloat(raw);

        return Number.isFinite(n) && n >= 0 ? n : 0;
    }

    sanitize(el) {
        let s = el.value.replace(/,/g, '.').replace(/[^\d.]/g, '');
        const dot = s.indexOf('.');
        if (-1 !== dot) {
            s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, '');
        }
        if (s !== el.value) {
            el.value = s;
        }
    }

    toNumber(value) {
        const n = parseFloat(value);

        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    format(x, digits) {
        return String(parseFloat(x.toFixed(digits)));
    }
}
