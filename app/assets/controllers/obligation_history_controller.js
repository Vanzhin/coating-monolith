import { Controller } from '@hotwired/stimulus';

/**
 * Лениво подгружает историю одной позиции (лента выдач+списаний) в карточку на странице выдачи — только
 * на ПЕРВОЕ раскрытие (Bootstrap `show.bs.collapse` на самом .collapse-блоке), дальше кэш в DOM, повторно
 * не фетчим. Единый паттерн с entity_preview/coating_preview_loader: лёгкая карточка — тяжёлое по запросу.
 */
export default class extends Controller {
    static values = { url: String };
    static targets = ['body'];

    load() {
        if ('1' === this.element.dataset.loaded) {
            return;
        }
        this.element.dataset.loaded = '1';

        fetch(this.urlValue, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.text();
            })
            .then((html) => {
                this.bodyTarget.innerHTML = html;
            })
            .catch(() => {
                delete this.element.dataset.loaded; // дать повторную попытку на следующее раскрытие
                this.bodyTarget.innerHTML = '<div class="p-3 text-danger small">Не удалось загрузить историю. Сверните и раскройте карточку ещё раз.</div>';
            });
    }
}
