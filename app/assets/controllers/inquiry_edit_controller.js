import { Controller } from '@hotwired/stimulus';

/**
 * Каскад осей маркировки в форме правки параметров запроса: стандарт → категория + долговечность.
 * Варианты осей зависят от стандарта (дерево в tree-value); у части категорий (ISO CX/Im4) второй
 * оси нет — блок долговечности прячем. Повторяет логику фасета поиска систем, но в живой форме-модалке.
 * Прогрессивное улучшение: сервер уже отрисовал опции текущего стандарта, контроллер лишь
 * переинициализирует их при смене стандарта/категории.
 *
 * Targets: standardSelect, categoryWrap/categorySelect/categoryLabel, durabilityWrap/durabilitySelect/durabilityLabel.
 * Values: tree — {standard: {primaryLabel, secondaryLabel, categories[], durabilities[], categoriesWithoutSecondaryAxis[]}};
 *         initialCategory/initialDurability — значения для предзаполнения после перерисовки опций.
 */
export default class extends Controller {
    static targets = [
        'standardSelect',
        'categoryWrap', 'categorySelect', 'categoryLabel',
        'durabilityWrap', 'durabilitySelect', 'durabilityLabel',
    ];

    static values = {
        tree: Object,
        initialCategory: { type: String, default: '' },
        initialDurability: { type: String, default: '' },
    };

    connect() {
        this._apply(this.initialCategoryValue, this.initialDurabilityValue);
    }

    onStandardChange() {
        this._apply('', '');
    }

    onCategoryChange() {
        this._toggleDurability();
    }

    _apply(category, durability) {
        const std = this.treeValue[this.standardSelectTarget.value];
        if (!std) {
            this.categoryWrapTarget.hidden = true;
            this.durabilityWrapTarget.hidden = true;
            return;
        }

        this.categoryWrapTarget.hidden = false;
        this.categoryLabelTarget.textContent = std.primaryLabel;
        this.durabilityLabelTarget.textContent = std.secondaryLabel;
        this._fill(this.categorySelectTarget, std.categories, category);
        this._fill(this.durabilitySelectTarget, std.durabilities, durability);
        this._toggleDurability();
    }

    _fill(select, options, selected) {
        select.innerHTML = '';
        select.appendChild(this._option('', '— не задано —', false));
        options.forEach(o => select.appendChild(this._option(o.value, o.label, o.value === selected)));
    }

    _option(value, label, selected) {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        opt.selected = selected;
        return opt;
    }

    _toggleDurability() {
        const std = this.treeValue[this.standardSelectTarget.value];
        if (!std) {
            this.durabilityWrapTarget.hidden = true;
            return;
        }

        const category = this.categorySelectTarget.value;
        const noAxis = '' !== category && std.categoriesWithoutSecondaryAxis.includes(category);
        this.durabilityWrapTarget.hidden = noAxis || 0 === std.durabilities.length;
        if (this.durabilityWrapTarget.hidden) {
            this.durabilitySelectTarget.value = '';
        }
    }
}
