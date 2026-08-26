import { Controller } from '@hotwired/stimulus';
import { openReferencePreview } from '../reference_helpers';

/**
 * Ленивая загрузка модалки превью системы по клику на чип в превью покрытия
 * (блок «входит в системы»). Чип несёт data-system-id; контроллер фетчит серверный
 * фрагмент _coating_system_preview.html.twig и показывает его поверх (стек Bootstrap).
 *
 * Values:
 *   endpoint  — URL-шаблон превью системы с плейсхолдером id.
 * Targets:
 *   container — пустой <div>, куда кладётся фрагмент модалки.
 */
export default class extends Controller {
    static values = { endpoint: String };
    static targets = ['container'];

    async open(event) {
        // Чип лежит внутри триггера модалки покрытия — гасим всплытие.
        event.stopPropagation();

        const systemId = event.currentTarget.dataset.systemId;
        if (systemId) {
            await openReferencePreview(this.endpointValue, systemId);
        }
    }
}
