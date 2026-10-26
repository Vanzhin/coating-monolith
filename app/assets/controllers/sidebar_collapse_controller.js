import { Controller } from '@hotwired/stimulus';

/*
 * Сворачивание десктоп-сайдбара в рельс из иконок. Класс `sidebar-collapsed`
 * ставится на <html> (как data-bs-theme у темы) — чтобы FOUC-safe init в <head>
 * base.html.twig мог применить состояние ДО первой отрисовки, без вспышки
 * 240px→64px. Состояние персистится в localStorage ('sidebarCollapsed' = '1'/'0').
 * Разметку не трогаем: переворот стрелки и скрытие подписей — чистый CSS по классу.
 * Ярлык кнопки (aria-label/title) синхронизируем с состоянием — иначе в свёрнутом
 * виде контрол «врал» бы («Свернуть», хотя разворачивает), в т.ч. после FOUC-reload.
 */
export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.syncLabel();
    }

    toggle() {
        document.documentElement.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem('sidebarCollapsed', this.isCollapsed ? '1' : '0');
        } catch (e) { /* localStorage заблокирован — состояние просто не переживёт перезагрузку */ }
        this.syncLabel();
    }

    get isCollapsed() {
        return document.documentElement.classList.contains('sidebar-collapsed');
    }

    syncLabel() {
        if (!this.hasToggleTarget) return;
        const label = this.isCollapsed ? 'Развернуть меню' : 'Свернуть меню';
        this.toggleTarget.setAttribute('aria-label', label);
        this.toggleTarget.setAttribute('title', label);
    }
}
