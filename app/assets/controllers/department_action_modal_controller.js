import { Controller } from '@hotwired/stimulus';

/**
 * Универсальная модалка «действие над отделом» (создать/переименовать/перенести/назначить
 * начальника) — одна и та же механика вместо четырёх копипаст (зеркалит подход
 * components/delete_modal.html.twig, но без специфики удаления).
 *
 * По клику на кнопку-триггер (data-bs-toggle="modal" data-bs-target="#...") читает data-bs-*
 * с кнопки и на 'show.bs.modal':
 *   - проставляет form[data-role="action-form"] action = data-bs-url;
 *   - для каждого [data-role="X"] внутри модалки (кроме action-form/parent-select) пишет
 *     значение/текст из data-bs-X с кнопки, по умолчанию пусто — открытие всегда «с нуля»,
 *     без утечки значения от предыдущего узла;
 *   - для [data-role="parent-select"] (модалка переноса) сбрасывает выбор и прячет вариант
 *     «перенести в самого себя» по data-bs-current-id — только UX-подсказка, реальную защиту
 *     от циклов даёт DepartmentTreePolicy на бэке;
 *   - сбрасывает любой async-typeahead внутри модалки (напр. поле «Начальник») через
 *     публичный clear() — у Tagify своя модель, обычный value-сброс её не трогает.
 */
export default class extends Controller {
    connect() {
        this._onShow = (event) => this._fill(event.relatedTarget);
        this.element.addEventListener('show.bs.modal', this._onShow);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this._onShow);
    }

    _fill(button) {
        if (!button) {
            return;
        }

        const url = button.getAttribute('data-bs-url');
        const form = this.element.querySelector('form[data-role="action-form"]');
        if (form && url) {
            form.setAttribute('action', url);
        }

        this.element.querySelectorAll('[data-role]').forEach((field) => {
            const role = field.dataset.role;
            if ('action-form' === role || 'parent-select' === role) {
                return;
            }
            const value = button.getAttribute(`data-bs-${role}`) || '';
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                field.value = value;
            } else {
                field.textContent = value;
            }
        });

        const parentSelect = this.element.querySelector('[data-role="parent-select"]');
        if (parentSelect) {
            const currentId = button.getAttribute('data-bs-current-id') || '';
            parentSelect.value = '';
            Array.from(parentSelect.options).forEach((option) => {
                option.hidden = '' !== currentId && option.value === currentId;
            });
        }

        this.element.querySelectorAll('[data-controller~="async-typeahead"]').forEach((wrapper) => {
            this.application.getControllerForElementAndIdentifier(wrapper, 'async-typeahead')?.clear();
        });
    }
}
