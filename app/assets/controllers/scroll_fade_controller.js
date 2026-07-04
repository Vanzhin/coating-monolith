import { Controller } from '@hotwired/stimulus';

/**
 * Общий контроллер для горизонтально-скроллируемых лент: выставляет на
 * элементе классы .scroll-fade-at-start / .scroll-fade-at-end в зависимости
 * от того, упирается ли скролл в соответствующий край (или контент вообще
 * не переполняет контейнер). Сам fade рисуется в CSS потребителя (обычно
 * через mask-image); контроллер только сообщает состояние через классы.
 *
 * Использование:
 *   <div class="coating-tags-scroll" data-controller="scroll-fade">…</div>
 * И в CSS:
 *   .coating-tags-scroll { mask-image: linear-gradient(…); }
 *   .coating-tags-scroll.scroll-fade-at-end { mask-image: none; }
 *
 * Классы обновляются при scroll, resize окна, а также при изменении
 * размера самого контейнера (ResizeObserver — ловит и мутации контента,
 * если тегов стало больше/меньше).
 */
export default class extends Controller {
    connect() {
        this._update = this._update.bind(this);
        this.element.addEventListener('scroll', this._update, { passive: true });
        this._resizeObserver = new ResizeObserver(this._update);
        this._resizeObserver.observe(this.element);
        this._update();
    }

    disconnect() {
        this.element.removeEventListener('scroll', this._update);
        this._resizeObserver?.disconnect();
    }

    _update() {
        const el = this.element;
        // +1px допуск на округление в браузерах при zoom / device pixel ratio.
        const overflows = el.scrollWidth > el.clientWidth + 1;
        const atStart = !overflows || el.scrollLeft <= 1;
        const atEnd = !overflows || el.scrollLeft + el.clientWidth >= el.scrollWidth - 1;
        el.classList.toggle('scroll-fade-at-start', atStart);
        el.classList.toggle('scroll-fade-at-end', atEnd);
    }
}
