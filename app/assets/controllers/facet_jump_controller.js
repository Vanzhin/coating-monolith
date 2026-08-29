import { Controller } from '@hotwired/stimulus';
import { Offcanvas } from 'bootstrap';

/*
 * Клик по фасет-чипу открывает шторку «Все фильтры» и прокручивает к блоку
 * этого фасета (подсветка на ~1.6с). Заменяет плавающие dropdown-поповеры:
 * выбор значения — только в шторке.
 *
 * Вешается на форму entity_search:
 *   data-controller="… facet-jump" data-facet-jump-drawer-value="{{ drawer_id }}"
 * Чип:
 *   <button data-action="click->facet-jump#jump" data-facet-jump-facet-param="appMinTemp">…</button>
 * Блок фасета в шторке: id="facet-appMinTemp".
 */
export default class extends Controller {
    static values = { drawer: String };

    jump(event) {
        const facet = event.params.facet;
        const drawerEl = document.getElementById(this.drawerValue);
        if (!drawerEl) return;
        event.preventDefault();

        const scrollToFacet = () => {
            const target = drawerEl.querySelector(`#facet-${facet}`);
            if (!target) return;
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            target.classList.add('facet-hl');
            window.setTimeout(() => target.classList.remove('facet-hl'), 1600);
        };

        if (drawerEl.classList.contains('show')) {
            scrollToFacet();
            return;
        }
        const onShown = () => {
            drawerEl.removeEventListener('shown.bs.offcanvas', onShown);
            scrollToFacet();
        };
        drawerEl.addEventListener('shown.bs.offcanvas', onShown);
        Offcanvas.getOrCreateInstance(drawerEl).show();
    }
}
