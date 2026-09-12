import { Controller } from '@hotwired/stimulus';
import { ZERO_UUID, openFragmentModal } from '../reference_helpers';
import { withPending } from '../pending';

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

        const trigger = event.currentTarget;
        const coatingId = trigger.dataset.coatingId;
        if (!coatingId) {
            return;
        }

        // Deep-link по бейджу «стойкое к»: подсветить вещество в chem-секции фрагмента.
        const highlightSubstanceId = trigger.dataset.highlightSubstanceId;

        // withPending гасит триггер и режет повторные клики, пока фрагмент грузится.
        await withPending(trigger, async () => {
            try {
                await openFragmentModal(
                    this.endpointValue.replace(ZERO_UUID, coatingId),
                    highlightSubstanceId
                        ? (modalEl) => modalEl.setAttribute('data-highlight-substance-id', highlightSubstanceId)
                        : null,
                );
            } catch {
                alert('Не удалось загрузить покрытие. Попробуйте ещё раз.');
            }
        });
    }
}
