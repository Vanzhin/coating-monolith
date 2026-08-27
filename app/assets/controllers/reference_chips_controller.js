import { Controller } from '@hotwired/stimulus';
import { fetchTitlesByIds, openReferencePreview } from '../reference_helpers';

/**
 * Серверно-отрендеренные чипы-референсы (система/покрытие) в фрагменте превью документа:
 * подтягивает имена по id (by-ids Coatings, кросс-контекст) и по клику открывает превью объекта.
 * Единый вид с блоками «входит в системы» / «распространяется на».
 *
 * Chip несёт data-ref-type (coating_system|coating) + data-ref-id и span[data-role=refTitle].
 * Values: byIdsSystem/byIdsCoating, previewSystem/previewCoating (шаблоны URL с плейсхолдером id).
 */
export default class extends Controller {
    static targets = ['chip'];
    static values = {
        byIdsSystem: String,
        byIdsCoating: String,
        previewSystem: String,
        previewCoating: String,
    };

    connect() {
        this._hydrate();
    }

    async open(event) {
        const chip = event.currentTarget;
        const template = 'coating' === chip.dataset.refType ? this.previewCoatingValue : this.previewSystemValue;
        await openReferencePreview(template, chip.dataset.refId);
    }

    async _hydrate() {
        const groups = { coating_system: [], coating: [] };
        this.chipTargets.forEach(chip => {
            if (groups[chip.dataset.refType]) {
                groups[chip.dataset.refType].push(chip);
            }
        });

        for (const [type, chips] of Object.entries(groups)) {
            if (0 === chips.length) {
                continue;
            }
            const endpoint = 'coating' === type ? this.byIdsCoatingValue : this.byIdsSystemValue;
            const titles = await fetchTitlesByIds(endpoint, chips.map(c => c.dataset.refId));
            chips.forEach(chip => {
                const title = titles.get(chip.dataset.refId);
                const label = chip.querySelector('[data-role="refTitle"]');
                if (label) {
                    label.textContent = title || ('coating' === chip.dataset.refType ? 'Покрытие' : 'Система');
                }
                if (title) {
                    chip.title = title;
                }
            });
        }
    }
}
