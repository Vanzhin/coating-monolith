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
 */
export default class extends Controller {
    static targets = ['slot'];

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
}
