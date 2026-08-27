import { Controller } from '@hotwired/stimulus';
import { openReferencePreview } from '../reference_helpers';

/**
 * Универсальный ленивый загрузчик модалки-превью сущности по клику на карточку/строку.
 * Элемент-триггер несёт data-entity-id; контроллер фетчит серверный фрагмент по шаблону URL
 * и показывает поверх (стек в body — см. openFragmentModal). Единый паттерн для покрытий,
 * систем и документов: список — лёгкие карточки, тяжёлое превью — по запросу.
 *
 * Values: endpoint — шаблон URL превью с плейсхолдером id.
 */
export default class extends Controller {
    static values = { endpoint: String };

    async open(event) {
        const id = event.currentTarget.dataset.entityId;
        if (id) {
            await openReferencePreview(this.endpointValue, id);
        }
    }
}
