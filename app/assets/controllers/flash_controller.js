import { Controller } from '@hotwired/stimulus';

/*
 * Авто-скрытие flash-тостов: success — 5 сек (короткое подтверждение),
 * warning/danger/info — 10 сек (нужно успеть прочитать). Inline-алерты вне
 * .flash-messages (ошибки формы, empty-state) не трогаем — этот контроллер
 * висит только на контейнере .flash-messages. Перенесено из inline-<script>.
 */
export default class extends Controller {
    connect() {
        this.timers = [];
        this.element.querySelectorAll('.alert').forEach((alert) => {
            const delay = alert.classList.contains('alert-success') ? 5000 : 10000;
            this.timers.push(setTimeout(() => {
                window.bootstrap?.Alert.getOrCreateInstance(alert).close();
            }, delay));
        });
    }

    disconnect() {
        this.timers.forEach(clearTimeout);
    }
}
