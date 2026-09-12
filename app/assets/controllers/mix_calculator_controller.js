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
    static targets = ['rows', 'row', 'part', 'qty', 'rowNum', 'totalPart', 'totalQty', 'rowTemplate', 'baseSeg', 'coatingMsg'];

    connect() {
        this.anchorEl = null;
        this.coatingRatio = null; // {volume:number[]|null, mass:number[]|null} выбранного покрытия
        this.locked = false;      // пропорция из покрытия — части не редактируются
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

    // ── Подстановка из покрытия (для залогиненных) ──

    /** Событие async-typeahead:select — выбрано покрытие; берём его mixingRatio. */
    applyFromCoating(event) {
        const ratio = event.detail?.item?.mixingRatio ?? null;
        const hasVolume = Array.isArray(ratio?.volume);
        const hasMass = Array.isArray(ratio?.mass);

        if (!hasVolume && !hasMass) {
            // У покрытия нет соотношения — сообщаем и оставляем ручной ввод.
            this.coatingRatio = null;
            this.unlock();
            this.showBaseSeg(false);
            this.showMsg('У этого покрытия нет данных о соотношении — введите пропорцию вручную.');
            return;
        }

        this.coatingRatio = ratio;
        this.showMsg(null);
        this.showBaseSeg(hasVolume && hasMass);
        const base = hasVolume ? 'volume' : 'mass';
        this.setBaseRadio(base);
        this.loadBase(base);
    }

    /** Смена сегмента Объём/Масса — перезалить части из выбранной базы. */
    changeBase(event) {
        if (this.coatingRatio) {
            this.loadBase(event.target.value);
        }
    }

    /** «Убрать покрытие» (снятие тега typeahead) — вернуть ручной режим, значения оставить. */
    clearCoating() {
        this.coatingRatio = null;
        this.unlock();
        this.showBaseSeg(false);
        this.showMsg(null);
    }

    loadBase(base) {
        const parts = this.coatingRatio?.[base];
        if (!Array.isArray(parts) || parts.length < 2) {
            return;
        }
        this.setRowCount(parts.length);
        this.partTargets.forEach((el, i) => { el.value = parts[i] ?? ''; });
        this.anchorEl = null; // части сменились — сбрасываем опору
        this.lock();
        this.recompute();
    }

    /** Доводит число строк калькулятора до n (клонируя/убирая последние). */
    setRowCount(n) {
        while (this.partTargets.length < n) {
            this.rowsTarget.appendChild(this.rowTemplateTarget.content.cloneNode(true));
        }
        while (this.partTargets.length > n) {
            this.rowTargets[this.rowTargets.length - 1].remove();
        }
        this.renumber();
    }

    lock() {
        this.locked = true;
        this.element.classList.add('mix-locked');
        this.partTargets.forEach((el) => { el.readOnly = true; });
    }

    unlock() {
        this.locked = false;
        this.element.classList.remove('mix-locked');
        this.partTargets.forEach((el) => { el.readOnly = false; });
    }

    showBaseSeg(show) {
        if (this.hasBaseSegTarget) {
            this.baseSegTarget.hidden = !show;
        }
    }

    setBaseRadio(base) {
        const radio = this.element.querySelector(`input[name="mixBase"][value="${base}"]`);
        if (radio) {
            radio.checked = true;
        }
    }

    showMsg(text) {
        if (!this.hasCoatingMsgTarget) {
            return;
        }
        this.coatingMsgTarget.textContent = text ?? '';
        this.coatingMsgTarget.hidden = !text;
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
