import { Controller } from '@hotwired/stimulus';

/**
 * Калькулятор толщины плёнки (мокрая ↔ сухая) с учётом разбавления. Считает в браузере — офлайн.
 * Формула — та же, что в доменном FilmThicknessCalculator (источник истины на бэке):
 *   VS_эфф = VS / (1 + D/100);  WFT = DFT × 100 / VS_эфф;  DFT = WFT × VS_эфф / 100.
 *
 * Обе стороны: толщина, в которую ввели последней — опорная (.is-anchor), вторая пересчитывается.
 * Сухой остаток и разбавление — параметры. Сухой остаток можно подставить из покрытия (для
 * залогиненных) — тогда поле запирается. Ввод фильтруется, запятая → точка.
 */
export default class extends Controller {
    static targets = ['vs', 'dilution', 'dry', 'wet', 'effVs', 'coatingMsg'];

    connect() {
        this.anchor = null; // 'dry' | 'wet' — какую толщину ввели, от неё считаем
        this.recompute();
    }

    onInput(event) {
        const el = event.target;
        this.sanitize(el);
        if (el === this.dryTarget) {
            this.anchor = 'dry';
        } else if (el === this.wetTarget) {
            this.anchor = 'wet';
        }
        this.recompute();
    }

    recompute() {
        const vs = this.toNumber(this.vsTarget.value);
        const dilution = this.toNumber(this.dilutionTarget.value); // 0/пусто → без разбавления
        const effVs = vs > 0 ? vs / (1 + dilution / 100) : 0;

        // Фактический сухой остаток (после разбавления) — показываем всегда, зависит только от VS и D.
        if (this.hasEffVsTarget) {
            this.effVsTarget.textContent = effVs > 0 ? this.format(effVs) : '—';
        }

        this.dryTarget.classList.toggle('is-anchor', this.anchor === 'dry');
        this.wetTarget.classList.toggle('is-anchor', this.anchor === 'wet');
        if (null === this.anchor) {
            return;
        }

        const computed = 'dry' === this.anchor ? this.wetTarget : this.dryTarget;
        if (effVs <= 0) {
            computed.value = '';
            return;
        }

        if ('dry' === this.anchor) {
            const dry = this.toNumber(this.dryTarget.value);
            this.wetTarget.value = dry > 0 ? this.format(dry * 100 / effVs) : '';
        } else {
            const wet = this.toNumber(this.wetTarget.value);
            this.dryTarget.value = wet > 0 ? this.format(wet * effVs / 100) : '';
        }
    }

    // ── Подстановка из покрытия (для залогиненных) ──

    /** Событие async-typeahead:select — выбрано покрытие; берём его сухой остаток (volumeSolid). */
    applyFromCoating(event) {
        const vs = event.detail?.item?.volumeSolid;
        if ('number' !== typeof vs || vs <= 0) {
            this.clearCoating();
            this.showMsg('У этого покрытия нет данных о сухом остатке — введите вручную.');
            return;
        }
        this.vsTarget.value = String(vs);
        this.lockVs();
        this.showMsg(null);
        this.recompute();
    }

    /** «Убрать покрытие» (снятие тега typeahead) — вернуть ручной ввод сухого остатка. */
    clearCoating() {
        this.unlockVs();
        this.showMsg(null);
    }

    lockVs() {
        this.vsTarget.readOnly = true;
        this.vsTarget.classList.add('film-locked');
    }

    unlockVs() {
        this.vsTarget.readOnly = false;
        this.vsTarget.classList.remove('film-locked');
    }

    showMsg(text) {
        if (!this.hasCoatingMsgTarget) {
            return;
        }
        this.coatingMsgTarget.textContent = text ?? '';
        this.coatingMsgTarget.hidden = !text;
    }

    /** Пропускает только цифры и один разделитель; запятую с мобильной клавиатуры → точку. */
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

    /** Толщина под показ: до 1 знака, без хвостового нуля. */
    format(x) {
        return String(parseFloat(x.toFixed(1)));
    }
}
