import { Controller } from '@hotwired/stimulus';

/**
 * Сворачивает ряд чипов до одного видимого ряда: чипы со второго ряда и ниже скрываются,
 * появляется кнопка «Ещё N». Клик раскрывает все («Свернуть» — обратно).
 *
 * Замер по offsetTop делаем после показа модалки (в скрытой модалке размеры нулевые),
 * плюс пересчёт на ресайзе, пока свёрнуто.
 *
 * Targets: chip (измеряемые/скрываемые чипы), toggle (кнопка), label (её подпись).
 */
export default class extends Controller {
    static targets = ['chip', 'toggle', 'label'];

    connect() {
        this._expanded = false;
        this._recollapse = () => { if (!this._expanded) this._collapse(); };

        this._modalEl = this.element.closest('.modal');
        if (this._modalEl) {
            this._modalEl.addEventListener('shown.bs.modal', this._recollapse);
        } else {
            this._collapse();
        }
        window.addEventListener('resize', this._recollapse);
    }

    disconnect() {
        window.removeEventListener('resize', this._recollapse);
        this._modalEl?.removeEventListener('shown.bs.modal', this._recollapse);
    }

    toggle() {
        this._expanded = !this._expanded;
        if (this._expanded) {
            this.chipTargets.forEach(chip => chip.classList.remove('d-none'));
            this.labelTarget.textContent = 'Свернуть';
        } else {
            this._collapse();
        }
    }

    _collapse() {
        const chips = this.chipTargets;
        chips.forEach(chip => chip.classList.remove('d-none'));

        if (0 === chips.length) {
            this.toggleTarget.classList.add('d-none');
            return;
        }

        const firstRowTop = chips[0].offsetTop;
        let hidden = 0;
        chips.forEach((chip, index) => {
            if (index > 0 && chip.offsetTop > firstRowTop) {
                chip.classList.add('d-none');
                hidden++;
            }
        });

        if (hidden > 0) {
            this.labelTarget.textContent = `Ещё ${hidden}`;
            this.toggleTarget.classList.remove('d-none');
        } else {
            this.toggleTarget.classList.add('d-none');
        }
    }
}
