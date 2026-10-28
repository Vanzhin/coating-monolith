import { Controller } from '@hotwired/stimulus';
import { openFragmentModal } from '../reference_helpers';
import { withPending } from '../pending';

/**
 * Ленивая модалка «текст запроса целиком». По клику фетчит серверный фрагмент
 * (_text_modal.html.twig) и показывает поверх — чтобы не грузить длинный текст в карточку.
 *
 * Values:
 *   endpoint — URL фрагмента текста запроса.
 */
export default class extends Controller {
    static values = { endpoint: String };

    open(event) {
        return withPending(event.currentTarget, () => openFragmentModal(this.endpointValue));
    }
}
