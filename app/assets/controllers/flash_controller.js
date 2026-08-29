import { Controller } from '@hotwired/stimulus';

/*
 * Авто-скрытие flash-тостов: success — 5 сек (короткое подтверждение),
 * прочее (warning/danger/info) — 10 сек (нужно успеть прочитать). Крестик —
 * data-action="click->flash#dismiss". Контроллер висит на контейнере
 * .flash-messages; каждый тост — target "toast". Inline-алерты вне контейнера
 * (ошибки формы, empty-state, банер impersonation) не трогаем.
 */
export default class extends Controller {
    static targets = ['toast'];

    connect() {
        this.timers = [];
        this.toastTargets.forEach((toast) => {
            const delay = toast.dataset.flashType === 'success' ? 5000 : 10000;
            this.timers.push(setTimeout(() => this._hide(toast), delay));
        });
    }

    disconnect() {
        this.timers.forEach(clearTimeout);
    }

    dismiss(event) {
        this._hide(event.currentTarget.closest('[data-flash-target="toast"]'));
    }

    _hide(toast) {
        if (!toast || toast.dataset.hiding) return;
        toast.dataset.hiding = '1';
        toast.classList.add('flash-toast--out');
        const remove = () => toast.remove();
        toast.addEventListener('transitionend', remove, { once: true });
        // Fallback на случай, если transitionend не сработает.
        setTimeout(remove, 400);
    }
}
