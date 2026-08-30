/*
 * Клиентский flash-тост в стиле приложения. Клонирует <template id="tpl-flash-toast">
 * (объявлен в base.html.twig), чтобы не дублировать разметку в JS, и добавляет его в
 * контейнер .flash-messages — тот же вид и позиционирование, что у серверных флешей.
 * Авто-скрытие: success — 5с, прочее — 10с; крестик закрывает сразу.
 *
 *   import { showToast } from '../flash_toast.js';
 *   showToast('Нужна хотя бы одна точка.', 'warning');
 *
 * type: 'success' | 'danger' | 'warning' | 'info'. Fallback (нет контейнера/шаблона)
 * — window.alert, чтобы сообщение не потерялось.
 */
const ICONS = { success: 'check-circle', danger: 'exclamation-octagon', warning: 'exclamation-triangle', info: 'info-circle' };
const KINDS = { success: 'ok', danger: 'err', warning: 'warn', info: 'info' };

export function showToast(message, type = 'info') {
    const container = document.querySelector('.flash-messages');
    const tpl = document.getElementById('tpl-flash-toast');
    if (!container || !tpl || !tpl.content.firstElementChild) {
        window.alert(message);
        return;
    }

    const el = tpl.content.firstElementChild.cloneNode(true);
    el.classList.add(`flash-toast--${KINDS[type] || 'info'}`);
    const icon = el.querySelector('.flash-toast-ic i');
    if (icon) icon.className = `bi bi-${ICONS[type] || 'info-circle'}`;
    el.querySelector('.flash-toast-body').textContent = message;
    container.appendChild(el);

    const remove = () => {
        if (el.dataset.hiding) return;
        el.dataset.hiding = '1';
        el.classList.add('flash-toast--out');
        setTimeout(() => el.remove(), 300);
    };
    el.querySelector('.flash-toast-x').addEventListener('click', remove);
    setTimeout(remove, type === 'success' ? 5000 : 10000);
}
