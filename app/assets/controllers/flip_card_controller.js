import { Controller } from '@hotwired/stimulus';

/**
 * 3D-flip карточка для фильтра-параметра. Две стороны (front / back) —
 * пресеты и точный ввод. Клик по иконке в шапке переворачивает карту.
 *
 * Обе стороны всегда в DOM. Неактивная сторона обёрнута в <fieldset disabled>,
 * поэтому её input'ы не улетают при submit родительской формы. При flip
 * значения newly-hidden face стираются — чтобы после переключения на
 * противоположную сторону в URL не оставался «фантомный» state прошлого
 * режима, если пользователь ничего не выбрал/не ввёл.
 *
 * Для range-slider'ов внутри скрываемой стороны вызываем публичный reset()
 * через Stimulus application registry (иначе стираются только value input'ов,
 * но CSS-переменные заливки range-slider'а остаются с прошлыми процентами).
 */
export default class extends Controller {
    static targets = ['front', 'back'];

    toggle() {
        const isFlipped = this.element.classList.toggle('flipped');
        const hidden = isFlipped ? this.frontTarget : this.backTarget;
        const visible = isFlipped ? this.backTarget : this.frontTarget;

        hidden.disabled = true;
        visible.disabled = false;
        this._resetHiddenSide(hidden);
    }

    _resetHiddenSide(container) {
        // Для range-slider внутри скрываемой стороны — вызываем публичный
        // reset(), чтобы обнулились и CSS-переменные заливки. Через
        // application registry (не outlets — outlet-селектор ищется в
        // document scope, а нам нужен descendants only).
        container.querySelectorAll('[data-controller~="range-slider"]').forEach((el) => {
            const ctrl = this.application.getControllerForElementAndIdentifier(el, 'range-slider');
            if (ctrl && typeof ctrl.reset === 'function') {
                ctrl.reset();
            }
        });
        // Всё остальное (hidden input'ы range-filter-target'ов и т.п.) —
        // просто value=''. range-slider'ские value уже обнулены выше через reset().
        container.querySelectorAll('input').forEach((el) => {
            el.value = '';
        });
    }
}
