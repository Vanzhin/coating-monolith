import { Controller } from '@hotwired/stimulus';
import { openFragmentModal } from '../reference_helpers';
import { withPending } from '../pending';

/**
 * Ленивая модалка «исправить параметры и переподобрать». По клику фетчит серверный фрагмент
 * (_edit_modal.html.twig) с предзаполненной формой и показывает поверх карточки.
 *
 * Values:
 *   endpoint — URL фрагмента формы правки.
 */
export default class extends Controller {
    static values = { endpoint: String };

    open(event) {
        return withPending(event.currentTarget, () => openFragmentModal(this.endpointValue));
    }
}
