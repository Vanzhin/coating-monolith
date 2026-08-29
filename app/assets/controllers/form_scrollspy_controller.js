import { Controller } from '@hotwired/stimulus';

/*
 * Scroll-spy каркаса форм (components/form_page.html.twig). Подсвечивает
 * активный раздел в мобильных чипах-якорях и десктоп-TOC по мере прокрутки,
 * и даёт плавный скролл по клику (с учётом scroll-margin-top секций).
 *
 * Targets:
 *   section — секции формы (<section id class="fsec">), цели наблюдения;
 *   anchor  — чипы-якоря (мобайл);
 *   toc     — ссылки оглавления (десктоп).
 * Связь чип/ссылка ↔ секция — по data-anchor === section.id.
 *
 * Прогрессивно: без JS ссылки #id работают нативно (нативный якорный переход).
 */
export default class extends Controller {
    static targets = ['section', 'anchor', 'toc'];

    connect() {
        if (!this.hasSectionTarget) {
            return;
        }
        // Полоса «активности» примерно в верхней трети вьюпорта: секция считается
        // активной, когда её верх входит в зону -40%..-55% от краёв.
        this.observer = new IntersectionObserver((entries) => this.onIntersect(entries), {
            rootMargin: '-40% 0px -55% 0px',
            threshold: 0,
        });
        this.sectionTargets.forEach((s) => this.observer.observe(s));
    }

    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
        }
    }

    onIntersect(entries) {
        const hit = entries.find((e) => e.isIntersecting);
        if (hit && hit.target.id) {
            this.activate(hit.target.id);
        }
    }

    activate(id) {
        [...this.anchorTargets, ...this.tocTargets].forEach((el) => {
            el.classList.toggle('is-active', el.dataset.anchor === id);
        });
        this.centerChip(id);
    }

    // Активный чип-якорь подтягиваем в центр горизонтальной ленты (без вертикального сдвига страницы).
    centerChip(id) {
        const chip = this.anchorTargets.find((el) => el.dataset.anchor === id);
        if (!chip || !chip.parentElement) {
            return;
        }
        const track = chip.parentElement;
        const left = chip.offsetLeft - track.clientWidth / 2 + chip.clientWidth / 2;
        track.scrollTo({ left, behavior: 'smooth' });
    }

    scrollTo(event) {
        const id = event.currentTarget.dataset.anchor;
        const target = this.sectionTargets.find((s) => s.id === id);
        if (!target) {
            return;
        }
        event.preventDefault();
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
        this.activate(id);
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + id);
        }
    }
}
