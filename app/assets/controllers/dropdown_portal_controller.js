import { Controller } from '@hotwired/stimulus';

/**
 * Заставляет Bootstrap dropdown-menu escape'ить ancestor'а с overflow:
 * hidden/auto/scroll (nav-tabs с overflow-auto, scroll-container и т.п.).
 * Работает через переключение Popper'а в strategy: 'fixed' — position: fixed
 * по CSS-спеке не клипится overflow'ом предков.
 *
 * Использование:
 *   <li class="dropdown" data-controller="dropdown-portal">
 *       <button data-bs-toggle="dropdown">...</button>
 *       <ul class="dropdown-menu">...</ul>
 *   </li>
 *
 * Логика: слушаем shown.bs.dropdown — это событие приходит уже ПОСЛЕ того,
 * как Bootstrap создал Popper-инстанс. Читаем его существующие модификаторы,
 * добавляем altAxis: true в preventOverflow (иначе меню у правого края
 * viewport'а вылезает за экран), и через popper.setOptions переключаем
 * strategy на fixed. Popper пересчитывает координаты автоматически.
 *
 * Есть один брифовый кадр между открытием (position: absolute от дефолтного
 * Bootstrap-конфига) и нашим setOptions (position: fixed). На практике
 * незаметно, ~16ms.
 */
export default class extends Controller {
    connect() {
        this.toggle = this.element.querySelector('[data-bs-toggle="dropdown"]');
        if (!this.toggle) return;
        this._shown = this._shown.bind(this);
        this.toggle.addEventListener('shown.bs.dropdown', this._shown);
    }

    disconnect() {
        if (this.toggle) {
            this.toggle.removeEventListener('shown.bs.dropdown', this._shown);
        }
    }

    _shown() {
        const dropdown = window.bootstrap?.Dropdown?.getInstance(this.toggle);
        const popper = dropdown?._popper;
        if (!popper) return;

        // Читаем оригинальные модификаторы из state.options.modifiers
        // (это то что Bootstrap передал при createPopper — обычно
        // [{preventOverflow, options: {boundary: 'clippingParents'}}, {offset, ...}]).
        // Не путать с orderedModifiers — там уже обработанный внутренний
        // список с data-полями, передавать его обратно нельзя.
        const existingModifiers = popper.state.options.modifiers || [];
        let hasPreventOverflow = false;
        const modifiers = existingModifiers.map(m => {
            if (m.name !== 'preventOverflow') return m;
            hasPreventOverflow = true;
            return { ...m, options: { ...m.options, altAxis: true, padding: 8 } };
        });
        if (!hasPreventOverflow) {
            modifiers.push({ name: 'preventOverflow', options: { altAxis: true, padding: 8 } });
        }

        popper.setOptions({
            strategy: 'fixed',
            modifiers,
        });
    }
}
