import { Controller } from '@hotwired/stimulus';

// Формула — та же, что в доменном DewPointCalculator (источник истины на бэке):
// Магнус, коэффициенты Alduchov–Eskridge (1996). Дублируем ради офлайна.
const MAGNUS_A = 17.625;
const MAGNUS_B = 243.04;
// Запас температуры поверхности над точкой росы (ISO 8502-4), °C.
const MARGIN_C = 3;

/**
 * Калькулятор точки росы и конденсации. Считает в браузере — офлайн.
 *   γ = ln(RH/100) + a·T/(b+T);  Td = b·γ/(a−γ).
 * Вердикт: наносить можно, если t поверхности ≥ точка росы + 3 °C. Температуры бывают
 * отрицательными — sanitize допускает ведущий минус. Ввод: запятая → точка.
 */
export default class extends Controller {
    static targets = ['airTemp', 'humidity', 'surfaceTemp', 'dewPoint', 'verdict'];

    connect() {
        this.recompute();
    }

    onInput(event) {
        this.sanitize(event.target);
        this.recompute();
    }

    recompute() {
        const t = this.parse(this.airTempTarget.value);
        const rh = this.parse(this.humidityTarget.value);

        // Точка росы — из температуры воздуха и влажности (0..100 %).
        if (null === t || null === rh || rh <= 0 || rh > 100) {
            this.dewPointTarget.textContent = '—';
            this.setVerdict(null);
            return;
        }

        const gamma = Math.log(rh / 100) + MAGNUS_A * t / (MAGNUS_B + t);
        const dewPoint = MAGNUS_B * gamma / (MAGNUS_A - gamma);
        this.dewPointTarget.textContent = this.format(dewPoint);

        // Вердикт — только когда задана температура поверхности.
        const surface = this.parse(this.surfaceTempTarget.value);
        if (null === surface) {
            this.setVerdict(null);
            return;
        }

        const reserve = surface - dewPoint;
        this.setVerdict(reserve >= MARGIN_C, this.format(reserve), this.format(dewPoint + MARGIN_C));
    }

    setVerdict(ok, reserve, min) {
        if (!this.hasVerdictTarget) {
            return;
        }
        if (null === ok) {
            this.verdictTarget.hidden = true;
            this.verdictTarget.textContent = '';
            return;
        }
        this.verdictTarget.className = 'badge ' + (ok ? 'text-bg-success' : 'text-bg-warning');
        this.verdictTarget.textContent = ok
            ? `Можно наносить · запас ${reserve} °C`
            : `Риск конденсата · нужно ≥ ${min} °C`;
        this.verdictTarget.hidden = false;
    }

    /** Цифры + один разделитель + ведущий минус (для отрицательных температур). Запятая → точка. */
    sanitize(el) {
        let s = el.value.replace(/,/g, '.').replace(/[^\d.-]/g, '');
        s = s.replace(/(?!^)-/g, ''); // минус только в начале
        const dot = s.indexOf('.');
        if (-1 !== dot) {
            s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, '');
        }
        if (s !== el.value) {
            el.value = s;
        }
    }

    parse(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : null;
    }

    /** До 1 знака, без хвостового нуля. */
    format(x) {
        return String(parseFloat(x.toFixed(1)));
    }
}
