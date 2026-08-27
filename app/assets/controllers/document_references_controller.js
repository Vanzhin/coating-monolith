import { Controller } from '@hotwired/stimulus';
import { fetchTitlesByIds, openReferencePreview } from '../reference_helpers';

/**
 * Ссылки-референсы документа по образцу редактирования слоёв системы:
 * прикреплённое — именованные строки (скрытые references[i][type]/[id]), + строка добавления
 * (селектор типа + async-typeahead + кнопка «Добавить»).
 *
 * - имя прикреплённых строк тянем by-ids из Coatings (кросс-контекст: имена систем/покрытий
 *   резолвим в браузере, не в PHP Certificates);
 * - «Добавить» берёт выбор из suggest и добавляет именованную строку;
 * - клик по имени открывает серверный фрагмент-модалку превью объекта.
 *
 * Индексы имён (references[i][...]) стабильны: префилл нумеруется на сервере, новые строки
 * получают следующий счётчик; дырки после удаления безвредны (PHP берёт values).
 *
 * Values — эндпоинты Coatings: suggestSystem/suggestCoating, byIdsSystem/byIdsCoating,
 * previewSystem/previewCoating (шаблоны URL с плейсхолдером id).
 */
export default class extends Controller {
    static targets = ['list', 'rowTemplate', 'row', 'addType', 'addPicker'];
    static values = {
        suggestSystem: String,
        suggestCoating: String,
        byIdsSystem: String,
        byIdsCoating: String,
        previewSystem: String,
        previewCoating: String,
    };

    connect() {
        this._seq = this.rowTargets.length;
        // async-typeahead строки добавления коннектится своим тиком — гидрируем после него.
        setTimeout(() => this._hydrate(), 0);
    }

    addTypeChanged() {
        const ctrl = this._typeahead(this.addPickerTarget);
        if (!ctrl) {
            return;
        }
        ctrl.endpointValue = 'coating' === this.addTypeTarget.value ? this.suggestCoatingValue : this.suggestSystemValue;
        ctrl.clear();
    }

    append() {
        const ctrl = this._typeahead(this.addPickerTarget);
        const selection = ctrl ? ctrl.getSelection() : null;
        if (!selection) {
            return;
        }

        const type = this.addTypeTarget.value;
        const node = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
        const idx = this._seq++;

        node.dataset.refType = type;
        node.dataset.refId = selection.id;
        const iconEl = node.querySelector('[data-role="typeIcon"]');
        iconEl.className = `bi flex-shrink-0 ${'coating' === type ? 'bi-square' : 'bi-layers'}`;
        iconEl.title = this._typeLabel(type);
        node.querySelector('[data-role="title"]').textContent = selection.title || selection.id;
        node.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('__INDEX__', idx);
        });
        node.querySelector('input[name$="[type]"]').value = type;
        node.querySelector('input[name$="[id]"]').value = selection.id;

        this.listTarget.appendChild(node);
        ctrl.clear();
    }

    remove(event) {
        event.target.closest('[data-document-references-target="row"]')?.remove();
    }

    async preview(event) {
        const row = event.target.closest('[data-document-references-target="row"]');
        if (!row || !row.dataset.refId) {
            return;
        }
        const template = 'coating' === row.dataset.refType ? this.previewCoatingValue : this.previewSystemValue;
        await openReferencePreview(template, row.dataset.refId);
    }

    async _hydrate() {
        const groups = { coating_system: [], coating: [] };
        this.rowTargets.forEach(row => {
            if (row.dataset.refId && groups[row.dataset.refType]) {
                groups[row.dataset.refType].push(row);
            }
        });

        for (const [type, rows] of Object.entries(groups)) {
            if (0 === rows.length) {
                continue;
            }
            const endpoint = 'coating' === type ? this.byIdsCoatingValue : this.byIdsSystemValue;
            const titles = await fetchTitlesByIds(endpoint, rows.map(r => r.dataset.refId));
            rows.forEach(row => {
                const titleEl = row.querySelector('[data-role="title"]');
                titleEl.textContent = titles.get(row.dataset.refId) || row.dataset.refId;
            });
        }
    }

    _typeLabel(type) {
        const option = this.addTypeTarget.querySelector(`option[value="${type}"]`);
        return option ? option.textContent.trim() : type;
    }

    _typeahead(el) {
        if (!el) {
            return null;
        }
        return this.application.getControllerForElementAndIdentifier(el, 'async-typeahead');
    }
}
