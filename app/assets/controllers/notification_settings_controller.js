import { Controller } from '@hotwired/stimulus';

/**
 * Аккордеон одной подписки на экране настроек уведомлений. Шапка показывает сводку активных каналов
 * (или «Выключено»); клик плавно раскрывает/сворачивает блок каналов (CSS grid-rows). Активность
 * выводится из каналов — мастер-тумблера нет: сняли все каналы → в шапке «Выключено». У каждого события
 * своя кнопка «Сохранить».
 *
 * Прогрессивно: тело в разметке раскрыто (форма работает без JS); на connect сворачиваем БЕЗ анимации
 * (класс is-anim добавляем кадром позже, чтобы не мигало на загрузке).
 *
 * Targets: body (grid-обёртка каналов), channel (чекбоксы), summary (сводка в шапке), chevron (стрелка).
 */
export default class extends Controller {
    static targets = ['body', 'channel', 'summary', 'chevron', 'status'];

    connect() {
        this.bodyTarget.classList.remove('is-open');
        this._syncChevron();
        this.refresh();
        requestAnimationFrame(() => this.bodyTarget.classList.add('is-anim'));
    }

    toggle() {
        this.bodyTarget.classList.toggle('is-open');
        this._syncChevron();
    }

    toggleOnKey(event) {
        if ('Enter' === event.key || ' ' === event.key) {
            event.preventDefault();
            this.toggle();
        }
    }

    refresh() {
        const labels = this.channelTargets.filter(c => c.checked).map(c => c.dataset.label);
        const on = labels.length > 0;
        this.summaryTarget.textContent = on ? labels.join(', ') : 'каналы не выбраны';
        if (this.hasStatusTarget) {
            this.statusTarget.className = 'bi bi-power fs-5 flex-shrink-0 ' + (on ? 'text-primary' : 'text-danger');
            this.statusTarget.title = on ? 'Подписка включена' : 'Подписка выключена';
        }
    }

    _syncChevron() {
        if (this.hasChevronTarget) {
            this.chevronTarget.classList.toggle('notif-chevron--up', this.bodyTarget.classList.contains('is-open'));
        }
    }
}
