import { Controller } from '@hotwired/stimulus';

/**
 * Калькулятор смешивания компонентов. Считает целиком в браузере — работает офлайн.
 *
 * Модель: колонка «часть» задаёт пропорцию; «количество» вводится в ЛЮБУЮ строку или в
 * «Итого» — это поле становится опорным (.is-anchor), остальные количества пересчитываются
 * по пропорции. Никакого переключателя режимов. Числа безразмерные (единиц нет).
 *
 * Ввод фильтруется на месте: в поля попадают только цифры и один разделитель (часть — не
 * более двух знаков после запятой). Отрицательные/буквы/мусор просто не набираются — поэтому
 * подсветки ошибок нет, ругаться не на что. Доменный инвариант дублируется в PartsRatio.
 */
export default class extends Controller {
    static targets = ['rows', 'row', 'part', 'qty', 'rowNum', 'totalPart', 'totalQty', 'rowTemplate'];

    connect() {
        this.anchorEl = null;
        this.renumber();
    }

    onInput(event) {
        const el = event.target;
        if (el.classList.contains('mix-part')) {
            this.sanitize(el, 2); // часть: целое или до 2 знаков
        } else if (el.classList.contains('mix-qty')) {
            this.sanitize(el, null); // количество: любые десятичные
            this.anchorEl = el; // правка количества переносит опору сюда
        }
        this.recompute();
    }

    addComponent() {
        this.rowsTarget.appendChild(this.rowTemplateTarget.content.cloneNode(true));
        this.renumber();
        this.recompute();
    }

    removeComponent(event) {
        if (this.rowTargets.length <= 2) {
            return;
        }
        const row = event.target.closest('.mix-row');
        if (this.anchorEl && row.contains(this.anchorEl)) {
            this.anchorEl = null;
        }
        row.remove();
        this.renumber();
        this.recompute();
    }

    renumber() {
        this.rowNumTargets.forEach((el, i) => { el.textContent = String(i + 1); });
    }

    recompute() {
        const parts = this.partTargets.map((el) => this.toNumber(el.value));
        const sum = parts.reduce((acc, p) => acc + p, 0);
        this.totalPartTarget.textContent = sum > 0 ? this.format(sum) : '0';

        const scale = this.scaleFromAnchor(parts, sum);

        this.qtyTargets.forEach((qtyEl, i) => {
            this.applyQty(qtyEl, (null !== scale && parts[i] > 0) ? parts[i] * scale : null);
        });
        this.applyQty(this.totalQtyTarget, (null !== scale && sum > 0) ? sum * scale : null);
    }

    /** Проставляет вычисленное количество в НЕопорное поле; опорное не трогаем (ввод юзера). */
    applyQty(qtyEl, computed) {
        const isAnchor = qtyEl === this.anchorEl;
        qtyEl.classList.toggle('is-anchor', isAnchor);
        if (isAnchor) {
            return;
        }
        qtyEl.value = null !== computed ? this.format(computed) : '';
    }

    /** Масштаб пропорции по опорному полю, либо null если считать не от чего. */
    scaleFromAnchor(parts, sum) {
        if (!this.anchorEl) {
            return null;
        }
        const value = this.toNumber(this.anchorEl.value);
        if (!(value > 0)) {
            return null;
        }
        if (this.anchorEl === this.totalQtyTarget) {
            return sum > 0 ? value / sum : null;
        }
        const index = this.qtyTargets.indexOf(this.anchorEl);
        if (index < 0 || !(parts[index] > 0)) {
            return null;
        }
        return value / parts[index];
    }

    /**
     * Пропускает в поле только цифры и один разделитель. Для части ограничивает число знаков
     * после запятой ($maxDecimals), для количества — без ограничения (null). Переприсваиваем
     * значение только когда реально что-то вырезали, чтобы не дёргать каретку при обычном вводе.
     */
    sanitize(el, maxDecimals) {
        let s = el.value.replace(/[^\d.]/g, '');
        const dot = s.indexOf('.');
        if (-1 !== dot) {
            s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, '');
            if (null !== maxDecimals) {
                const [intPart, decPart] = s.split('.');
                s = intPart + '.' + decPart.slice(0, maxDecimals);
            }
        }
        if (s !== el.value) {
            el.value = s;
        }
    }

    toNumber(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    /** Сырое число под показ: до 3 знаков, без хвостовых нулей. Точное округление — забота пользователя. */
    format(x) {
        return String(parseFloat(x.toFixed(3)));
    }
}
