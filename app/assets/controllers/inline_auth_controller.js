import { Controller } from '@hotwired/stimulus';
import { showToast } from '../flash_toast.js';
import { submitInlineForm } from '../inline_form.js';

/*
 * Инлайн-отправка форм входа / регистрации / входа-по-ссылке в свап-панели лендинга.
 * Не уводим со страницы: разбор ответа — общий хелпер submitInlineForm (конверт ResponseListener):
 *   - redirect — успех входа/регистрации → уводим в кабинет;
 *   - message  — провал → показываем тостом (как остальные ошибки приложения), форма остаётся;
 *   - html     — HTML-фрагмент (вход по ссылке): терминальный результат, подменяем слот.
 * Пока ждём — кнопка в состоянии data-loading. Без JS формы постят обычным способом.
 * В терминальном состоянии входа-по-ссылке стрелка «назад» (inline-auth#back) возвращает в
 * слот исходную форму (запомнили в connect), чтобы ввести другой адрес без перезагрузки.
 * Анти-спам держат серверные рейт-лимитеры, а не отсутствие формы в UI.
 */
export default class extends Controller {
    static targets = ['slot'];

    connect() {
        // Исходный HTML слота = чистая форма (терминал прилетает только из свапа ниже).
        // Запоминаем, чтобы стрелка «назад» в терминальном состоянии вернула форму.
        this.pristineSlotHtml = this.slotTarget.innerHTML;
        this.swapped = false;
    }

    async submit(event) {
        event.preventDefault();

        const form = event.target;
        const button = form.querySelector('button[type="submit"]');
        const originalLabel = button ? button.innerHTML : null;
        const restore = () => {
            if (button) {
                button.disabled = false;
                button.innerHTML = originalLabel;
            }
        };
        if (button) {
            button.disabled = true;
            button.textContent = button.getAttribute('data-loading') || 'Подождите…';
        }

        try {
            const result = await submitInlineForm(form);
            if (result.redirect) {
                window.location.assign(result.redirect);

                return;
            }
            if (undefined !== result.html) {
                this.slotTarget.innerHTML = result.html; // терминальный результат (вход по ссылке)
                this.swapped = true;

                return;
            }
            if (result.message) {
                showToast(result.message, 'danger');
            }
            restore();
        } catch (e) {
            restore();
            showToast('Не удалось отправить. Проверьте соединение и попробуйте ещё раз.', 'danger');
        }
    }

    // Стрелка «назад»: в терминальном состоянии («письмо отправлено») возвращает форму в слот
    // и оставляет на панели входа-по-ссылке (гасим CSS-переход к кнопкам через preventDefault).
    // В обычном состоянии не вмешиваемся — label for="auth-buttons" уводит к кнопкам как раньше.
    // CSRF stateless — восстановленный токен валиден; повтор упрётся в рейт-лимитер.
    back(event) {
        if (!this.swapped) {
            return;
        }
        event.preventDefault();
        this.slotTarget.innerHTML = this.pristineSlotHtml;
        this.swapped = false;
        this.slotTarget.querySelector('input[type="email"]')?.focus();
    }
}
