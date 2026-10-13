import { Controller } from '@hotwired/stimulus';

/**
 * Popover примечаний химстойкости на карточке обратного поиска. Весь вердикт-чип (trigger)
 * по клику/тапу раскрывает тело из скрытого <template> (content) — единственный источник
 * разметки (её рендерит Twig, не дублируем в JS).
 *
 * Триггер ручной + явный click-тоггл: `trigger:'focus'` не годится для мобильных — iOS Safari
 * не отдаёт focus кнопке по тапу, popover не открывался. Click срабатывает по тапу везде.
 *
 * Закрытие — document-листенером: тап вне своего чипа и вне своего popover закрывает его.
 * Отсюда же эксклюзивность «один открытый»: тап по другому чипу = тап вне первого → первый
 * закрывается сам, отдельный реестр не нужен. Работает и на догруженных infinite-scroll
 * карточках (Stimulus сам коннектит контроллер на вставленном DOM).
 */
export default class extends Controller {
    static targets = ['trigger', 'content'];

    connect() {
        if (!window.bootstrap || !window.bootstrap.Popover || !this.hasTriggerTarget || !this.hasContentTarget) {
            return;
        }
        this.shown = false;
        this.popover = new window.bootstrap.Popover(this.triggerTarget, {
            title: 'Примечания',
            content: this.contentTarget.innerHTML,
            html: true,
            trigger: 'manual',
            placement: 'top',
            customClass: 'note-popover',
        });
    }

    toggle(event) {
        event.preventDefault();
        this.shown ? this.hide() : this.show();
    }

    show() {
        if (!this.popover) {
            return;
        }
        this.popover.show();
        this.shown = true;
        // Свой триггер и своё тело popover — не закрываем; остальное (включая чужой чип) — закрываем.
        this.onDocClick = (e) => {
            if (this.triggerTarget.contains(e.target) || e.target.closest('.note-popover')) {
                return;
            }
            this.hide();
        };
        document.addEventListener('click', this.onDocClick);
    }

    hide() {
        if (!this.popover) {
            return;
        }
        this.popover.hide();
        this.shown = false;
        if (this.onDocClick) {
            document.removeEventListener('click', this.onDocClick);
            this.onDocClick = null;
        }
    }

    disconnect() {
        if (this.onDocClick) {
            document.removeEventListener('click', this.onDocClick);
            this.onDocClick = null;
        }
        if (this.popover) {
            this.popover.dispose();
            this.popover = null;
        }
    }
}
