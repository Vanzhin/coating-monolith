import { Controller } from '@hotwired/stimulus';

/*
 * Переключатель темы. Актуальная тема ставится на <html data-bs-theme=...>
 * FOUC-safe скриптом в <head>; здесь — обработка клика и синхронизация иконки
 * солнце/луна (targets light/dark). Перенесено из inline-<script> base.html.twig.
 */
export default class extends Controller {
    static targets = ['light', 'dark'];

    connect() {
        this.sync();
    }

    sync() {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        if (this.hasLightTarget) this.lightTarget.classList.toggle('d-none', isDark);
        if (this.hasDarkTarget) this.darkTarget.classList.toggle('d-none', !isDark);
    }

    toggle() {
        const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) { /* заблокирован — игнор */ }
        this.sync();
    }
}
