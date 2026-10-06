import { Controller } from '@hotwired/stimulus';

/**
 * «Списать всё»: проставляет каждому полю количества к списанию его максимум (max = на руках по позиции).
 * По аналогии с norm-fill#fill на странице выдачи, только здесь максимум — выданное/остаток, а не норма.
 */
export default class extends Controller {
    static targets = ['qty'];

    fillAll() {
        this.qtyTargets.forEach((input) => {
            const max = input.getAttribute('max');
            if (max !== null && max !== '') {
                input.value = max;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }
}
