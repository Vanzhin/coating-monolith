import { Controller } from '@hotwired/stimulus';

/**
 * Акт списания (черновик): «Списать всё» проставляет каждому полю количества его максимум (остаток на руках);
 * «Убрать» удаляет позицию из состава акта и пересчитывает счётчик «позиций: N» у группы человека (пустую
 * группу скрываем). Фактическое удаление — на сервере: убранная строка не уходит в сабмит, replacePortions
 * пересобирает акт без неё.
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

    remove(event) {
        const row = event.target.closest('[data-writeoff-row]');
        if (null === row) {
            return;
        }
        const group = row.closest('[data-writeoff-group]');
        row.remove();
        if (null === group) {
            return;
        }
        const count = group.querySelectorAll('[data-writeoff-row]').length;
        if (0 === count) {
            group.remove(); // у человека не осталось позиций к списанию — убираем блок целиком

            return;
        }
        const badge = group.querySelector('[data-writeoff-count]');
        if (null !== badge) {
            badge.textContent = `позиций: ${count}`;
        }
    }
}
