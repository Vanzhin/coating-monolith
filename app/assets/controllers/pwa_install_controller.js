import { Controller } from '@hotwired/stimulus';

/* Кнопка «Установить как приложение». Скрыта (d-none), пока браузер не выстрелит
   beforeinstallprompt (не поддерживается / уже установлено — остаётся скрытой).
   Клик — системный диалог установки. */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.deferred = null;
        this.onPrompt = (e) => { e.preventDefault(); this.deferred = e; this.show(); };
        this.onInstalled = () => this.hide();
        window.addEventListener('beforeinstallprompt', this.onPrompt);
        window.addEventListener('appinstalled', this.onInstalled);
    }

    disconnect() {
        window.removeEventListener('beforeinstallprompt', this.onPrompt);
        window.removeEventListener('appinstalled', this.onInstalled);
    }

    show() { if (this.hasButtonTarget) this.buttonTarget.classList.remove('d-none'); }
    hide() { if (this.hasButtonTarget) this.buttonTarget.classList.add('d-none'); }

    async install() {
        if (!this.deferred) return;
        this.deferred.prompt();
        await this.deferred.userChoice;
        this.deferred = null;
        this.hide();
    }
}
