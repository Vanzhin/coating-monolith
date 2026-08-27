import { Controller } from '@hotwired/stimulus';
import { ZERO_UUID, openFragmentModal } from '../reference_helpers';

/**
 * Ленивая загрузка модалки покрытия по клику на слой (data-coating-id). Фетчит серверный
 * фрагмент _coating_preview.html.twig и показывает поверх стека.
 *
 * Модалка кладётся в <body> (см. openFragmentModal), а не вложенно в текущую модалку —
 * иначе ESC и клик вне закрывали бы весь стек, а не только верхнюю.
 *
 * Values:
 *   endpoint — URL-шаблон превью покрытия с плейсхолдером id.
 */
export default class extends Controller {
    static values = { endpoint: String };

    async open(event) {
        // Слой лежит внутри триггера модалки системы — гасим всплытие.
        event.stopPropagation();

        const coatingId = event.currentTarget.dataset.coatingId;
        if (!coatingId || this._loading) {
            return;
        }

        // Deep-link по бейджу «стойкое к»: подсветить вещество в chem-секции фрагмента.
        const highlightSubstanceId = event.currentTarget.dataset.highlightSubstanceId;

        this._loading = true;
        try {
            await openFragmentModal(
                this.endpointValue.replace(ZERO_UUID, coatingId),
                highlightSubstanceId
                    ? (modalEl) => modalEl.setAttribute('data-highlight-substance-id', highlightSubstanceId)
                    : null,
            );
        } catch {
            alert('Не удалось загрузить покрытие. Попробуйте ещё раз.');
        } finally {
            this._loading = false;
        }
    }
}
