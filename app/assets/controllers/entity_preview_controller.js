import { Controller } from '@hotwired/stimulus';
import { openReferencePreview } from '../reference_helpers';
import { withPending } from '../pending';

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
        const trigger = event.currentTarget;
        const id = trigger.dataset.entityId;
        if (id) {
            await withPending(trigger, () => openReferencePreview(this.endpointValue, id));
        }
    }
}
