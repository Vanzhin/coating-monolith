import { Controller } from '@hotwired/stimulus';
import { fetchTitlesByIds } from '../reference_helpers';

/**
 * Гидрирует бейджи «Начальник» в дереве отделов: headUserUlid → email пользователя,
 * одним batch-запросом на всё дерево (Users\ByIdsAction). В дереве хранится только ulid
 * (Personnel не дублирует email юзера), поэтому подпись догружается на клиенте — как чипы
 * фасетов (reference-chips), но без превью, просто подмена текста.
 */
export default class extends Controller {
    static targets = ['chip'];
    static values = { byIdsUrl: String };

    connect() {
        this._hydrate();
    }

    async _hydrate() {
        const ids = [...new Set(this.chipTargets.map(chip => chip.dataset.headUlid).filter(Boolean))];
        if (0 === ids.length) {
            return;
        }

        const titles = await fetchTitlesByIds(this.byIdsUrlValue, ids);
        this.chipTargets.forEach(chip => {
            const title = titles.get(chip.dataset.headUlid);
            if (title) {
                chip.textContent = title;
            }
        });
    }
}
