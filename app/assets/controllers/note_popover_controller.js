import { Controller } from '@hotwired/stimulus';

/**
 * Popover примечаний химстойкости на карточке обратного поиска. Значок (trigger) по
 * клику/тапу раскрывает тело из скрытого <template> (content) — единственный источник
 * разметки (её рендерит Twig, не дублируем в JS). trigger:focus = открытие по клику,
 * закрытие по клику вне; работает и на догруженных infinite-scroll карточках (Stimulus
 * сам коннектит контроллер на вставленном DOM).
 */
export default class extends Controller {
    static targets = ['trigger', 'content'];

    connect() {
        if (!window.bootstrap || !window.bootstrap.Popover || !this.hasTriggerTarget || !this.hasContentTarget) {
            return;
        }
        this.popover = new window.bootstrap.Popover(this.triggerTarget, {
            title: 'Примечания',
            content: this.contentTarget.innerHTML,
            html: true,
            trigger: 'focus',
            placement: 'top',
            customClass: 'note-popover',
        });
    }

    disconnect() {
        if (this.popover) {
            this.popover.dispose();
            this.popover = null;
        }
    }
}
