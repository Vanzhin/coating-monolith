import { Controller } from '@hotwired/stimulus';

/**
 * Chip-фасеты списка покрытий:
 *
 *  1) Portal desktop chip-dropdown'ов в document.body.
 *     Chip-scroll имеет overflow: hidden (для horizontal-only скролла),
 *     из-за чего dropdown-menu, находясь как descendant, клипится.
 *     Physical DOM-move меню в <body> на show и обратно на hide решает это
 *     без хрупкого Popper strategy: 'fixed' + boundary/altAxis/tether.
 *     Bootstrap.Dropdown хранит ссылку на меню в _menu — перемещение не
 *     разрывает связь, Popper пересчитывает позицию от reference'а (кнопки)
 *     через viewport автоматически. Инпуты внутри menu имеют form="...",
 *     поэтому даже в <body> остаются участниками нашей формы.
 *
 *  2) Viewport-adaptive disabled на fieldset'ах.
 *     Инпуты из невидимого viewport'a (например, mobile offcanvas при
 *     десктопной ширине) физически остаются в form. Если бы они были
 *     enabled, browser отправлял бы их значения при submit — для hidden'ов
 *     range-фильтра и thermTemp это значит, что пустой mobile input
 *     перезатирает установленный desktop-ом. Disabled fieldset выключает
 *     все его инпуты (и submit-кнопки) для form-submit'а.
 *
 * Раньше эти две задачи жили inline-скриптом в самом Twig, что нарушало
 * CLAUDE.md («не пиши JS в Twig, смерть рефакторинга»).
 */
export default class extends Controller {
    connect() {
        this._dropdownCleanups = [];
        this._initPortalDropdowns();
        this._initViewportAdaptive();
        this._initMergeSubmit();
    }

    disconnect() {
        this._mediaQuery?.removeEventListener('change', this._applyViewport);
        this.element.removeEventListener('submit', this._onSubmit);
        this._dropdownCleanups.forEach(cleanup => cleanup());
        this._dropdownCleanups = [];
    }

    /**
     * Chip-row — форма ТОЛЬКО для добавления фасетов: активные фасеты
     * отрисованы чипами-ссылками (снятие — через merge-URL), а не полями формы.
     * Нативный submit собрал бы query лишь из видимых полей и стёр бы активные
     * фасеты («второй чип перебивает первый»). Поэтому мёрджим значения формы
     * поверх текущего URL: ключи, что есть в форме, заменяем целиком; остальные
     * (активные фасеты, sort, теги/покрытия) сохраняем. Пустые значения =
     * очистка. `#allFiltersForm` — отдельная полноформатная форма, её это не
     * касается.
     */
    _initMergeSubmit() {
        this._onSubmit = this._onSubmit.bind(this);
        this.element.addEventListener('submit', this._onSubmit);
    }

    _onSubmit(event) {
        event.preventDefault();

        const params = new URLSearchParams(window.location.search);
        const formData = new FormData(this.element);

        for (const key of new Set(formData.keys())) {
            params.delete(key);
        }
        for (const [key, value] of formData.entries()) {
            if ('' !== value) {
                params.append(key, value);
            }
        }
        params.delete('page');

        const action = this.element.getAttribute('action') || window.location.pathname;
        const qs = params.toString();
        window.location.assign(qs ? `${action}?${qs}` : action);
    }

    _initPortalDropdowns() {
        const Dropdown = window.bootstrap?.Dropdown;
        if (!Dropdown) return;

        for (const toggle of this.element.querySelectorAll('.chip-desktop-panel [data-bs-toggle="dropdown"]')) {
            Dropdown.getInstance(toggle)?.dispose();
            const dropdown = new Dropdown(toggle, {
                popperConfig: (defaultConfig) => ({
                    ...defaultConfig,
                    modifiers: [
                        ...defaultConfig.modifiers.filter(m => m.name !== 'flip'),
                        { name: 'flip', enabled: false },
                    ],
                }),
            });

            const menu = dropdown._menu;
            const originalParent = menu.parentElement;
            const onShow = () => document.body.appendChild(menu);
            const onHide = () => originalParent.appendChild(menu);

            toggle.addEventListener('show.bs.dropdown', onShow);
            toggle.addEventListener('hidden.bs.dropdown', onHide);

            this._dropdownCleanups.push(() => {
                toggle.removeEventListener('show.bs.dropdown', onShow);
                toggle.removeEventListener('hidden.bs.dropdown', onHide);
                // Если меню сейчас в body — вернуть на место, иначе dispose
                // оставит осиротевший узел.
                if (menu.parentElement !== originalParent) {
                    originalParent.appendChild(menu);
                }
                dropdown.dispose();
            });
        }
    }

    _initViewportAdaptive() {
        this._mediaQuery = window.matchMedia('(min-width: 768px)');
        const desktopPanels = this.element.querySelectorAll('.chip-desktop-panel');
        const mobilePanels = this.element.querySelectorAll('.chip-mobile-panel');

        this._applyViewport = () => {
            const isDesktop = this._mediaQuery.matches;
            desktopPanels.forEach(f => { f.disabled = !isDesktop; });
            mobilePanels.forEach(f => { f.disabled = isDesktop; });
        };
        this._applyViewport();
        this._mediaQuery.addEventListener('change', this._applyViewport);
    }
}
