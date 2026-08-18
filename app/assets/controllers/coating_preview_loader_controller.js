import { Controller } from '@hotwired/stimulus';

/**
 * Ленивая загрузка полной модалки покрытия по клику на слой системы.
 *
 * Слой несёт data-coating-id. По клику контроллер фетчит серверный фрагмент
 * (партиал _coating_preview.html.twig) с эндпоинта
 * app_cabinet_coating_coating_preview, кладёт его в общий контейнер и
 * показывает поверх — если клик был из модалки системы, модалка покрытия
 * встаёт стеком сверху (Bootstrap 5), закрытие возвращает к системе.
 *
 * Никакой разметки покрытия в JS не строим — она приходит с сервера единым
 * партиалом (тот же, что инлайном на вьюхе покрытий).
 *
 * Values:
 *   endpoint  — URL-шаблон превью с плейсхолдером id
 *               (напр. /cabinet/coating/coating/00000000-…-000/preview)
 * Targets:
 *   container — пустой <div>, куда кладётся фрагмент модалки
 */
export default class extends Controller {
    static values = { endpoint: String };

    static targets = ['container'];

    async open(event) {
        // Слой лежит внутри триггера модалки системы — гасим всплытие,
        // иначе поверх откроются обе модалки.
        event.stopPropagation();

        const coatingId = event.currentTarget.dataset.coatingId;
        if (!coatingId || this._loading) {
            return;
        }

        this._loading = true;
        try {
            const url = this.endpointValue.replace('00000000-0000-0000-0000-000000000000', coatingId);
            const response = await fetch(url, { headers: { 'Accept': 'text/html' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.containerTarget.innerHTML = await response.text();
            this._showFragmentModal();
        } catch (e) {
            this.containerTarget.innerHTML = '';
            alert('Не удалось загрузить покрытие. Попробуйте ещё раз.');
        } finally {
            this._loading = false;
        }
    }

    _showFragmentModal() {
        const modalEl = this.containerTarget.querySelector('.modal');
        if (!modalEl) {
            return;
        }
        // Чистим контейнер после закрытия — не копим осиротевшие фрагменты/backdrop'ы.
        modalEl.addEventListener('hidden.bs.modal', () => { this.containerTarget.innerHTML = ''; }, { once: true });
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}
