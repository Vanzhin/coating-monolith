import { Controller } from '@hotwired/stimulus';

/**
 * Значок-калькулятор у поля слоя на странице заполнения. По клику открывает калькулятор в шторке
 * (.modal-sheet). Для покрытие-зависимых (wet-film) ЛЕНИВО — только по клику — тянет контекст покрытия
 * ЭТОЙ строки слоя по id и ПОДСТАВЛЯЕТ его в поле «Из покрытия» калькулятора: покрытие становится видно,
 * а штатный async-typeahead:select → applyFromCoating засеивает сухой остаток. Нет покрытия/сети —
 * открывает калькулятор как есть. Точка росы из покрытия не сеется.
 */
export default class extends Controller {
    static values = { calc: String, modal: String, contextUrl: String };

    async open() {
        const modalEl = document.getElementById(this.modalValue);
        if (!modalEl) {
            return;
        }
        if ('dew-point' !== this.calcValue) {
            await this.seedFromLayerCoating(modalEl);
        }
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    async seedFromLayerCoating(modalEl) {
        const coatingId = this.layerCoatingId();
        if (!coatingId || !this.contextUrlValue) {
            return;
        }

        // Поле «Из покрытия» есть только у залогиненного (async-typeahead); нет — открываем как есть.
        const typeaheadEl = modalEl.querySelector('[data-controller~="async-typeahead"]');
        if (!typeaheadEl) {
            return;
        }

        let context = null;
        try {
            const url = this.contextUrlValue.replace('__CID__', encodeURIComponent(coatingId));
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (resp.ok) {
                const json = await resp.json();
                context = json.data ?? json; // глобальный ResponseListener оборачивает в {data}
            }
        } catch (e) {
            // сеть/ошибка — открываем калькулятор пустым
        }
        if (!context || !context.id) {
            return;
        }

        // Подставляем покрытие слоя в поле выбора — оно покажет название и через select засеет VS/ratio.
        const typeahead = this.application.getControllerForElementAndIdentifier(typeaheadEl, 'async-typeahead');
        if (typeahead) {
            typeahead.selectFullItem(context);
        }
    }

    /** id покрытия из coating-pick этой строки слоя (материал). Пусто → калькулятор без засева. */
    layerCoatingId() {
        const row = this.element.closest('[data-report-list-rows-target~="row"]');
        const hidden = row ? row.querySelector('[data-coating-pick-target="hidden"]') : null;
        return hidden ? hidden.value : null;
    }
}
