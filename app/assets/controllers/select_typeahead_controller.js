import { Controller } from '@hotwired/stimulus';
import Tagify from '@yaireo/tagify';

/**
 * Превращает обычный <select> в typeahead-поле (single-select) через Tagify.
 *
 * Использование:
 *   <div data-controller="select-typeahead"
 *        data-select-typeahead-placeholder-value="Выберите...">
 *     <select name="fieldName" data-select-typeahead-target="select" required>
 *       <option value="">...</option>
 *       <option value="uuid1">Название 1</option>
 *     </select>
 *   </div>
 *
 * Контроллер скрывает <select>, подставляет Tagify-инпут для поиска
 * и синхронизирует выбранный id в скрытый <input name="fieldName">.
 */
export default class extends Controller {
    static targets = ['select'];
    static values = {
        placeholder: { type: String, default: 'Выберите...' },
    };

    connect() {
        const select = this.selectTarget;
        const name = select.name;
        const required = select.required;

        // Строим whitelist из option'ов
        const whitelist = [];
        let preselected = null;
        Array.from(select.options).forEach(opt => {
            if (!opt.value) return;
            const item = { value: opt.textContent.trim(), id: opt.value };
            whitelist.push(item);
            if (opt.selected) preselected = item;
        });

        // Скрываем select, убираем required (иначе форма не сабмитится)
        select.style.display = 'none';
        select.required = false;
        select.name = '';

        // Создаём input для Tagify
        this._input = document.createElement('input');
        this._input.type = 'text';
        this._input.className = 'form-control';
        this._input.placeholder = this.placeholderValue;
        select.parentNode.insertBefore(this._input, select.nextSibling);

        // Скрытый input для передачи id в форму
        this._hidden = document.createElement('input');
        this._hidden.type = 'hidden';
        this._hidden.name = name;
        if (required) this._hidden.required = true;
        select.parentNode.insertBefore(this._hidden, select.nextSibling);

        this._tagify = new Tagify(this._input, {
            whitelist,
            enforceWhitelist: true,
            mode: 'select',
            dropdown: {
                enabled: 1,
                maxItems: 50,
                fuzzySearch: true,
                highlightFirst: true,
                closeOnSelect: true,
            },
            tagTextProp: 'value',
        });

        if (preselected) {
            this._tagify.addTags([preselected]);
            this._hidden.value = preselected.id;
        }

        this._tagify.on('add', e => {
            this._hidden.value = e.detail.data.id ?? '';
        });

        this._tagify.on('remove', () => {
            this._hidden.value = '';
        });
    }

    disconnect() {
        if (this._tagify) {
            this._tagify.destroy();
            this._tagify = null;
        }
        if (this._input && this._input.parentNode) {
            this._input.parentNode.removeChild(this._input);
            this._input = null;
        }
        if (this._hidden && this._hidden.parentNode) {
            this._hidden.parentNode.removeChild(this._hidden);
            this._hidden = null;
        }
        // Restore select
        const select = this.selectTarget;
        select.style.display = '';
    }
}
