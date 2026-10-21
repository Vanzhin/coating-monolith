import { Controller } from '@hotwired/stimulus';
import { showToast } from '../flash_toast.js';

/*
 * Инлайн-отправка форм входа / регистрации / входа-по-ссылке в свап-панели лендинга.
 * Не уводим со страницы: шлём fetch (X-Requested-With), сервер отвечает одним из:
 * ResponseListener оборачивает JSON в конверт { result, status, data, message }:
 *   - result:success, data.redirect — успех входа/регистрации → уводим в кабинет;
 *   - result:error,  message        — провал → показываем тостом (как остальные ошибки
 *     приложения), форма и введённое остаются на месте для исправления;
 *   - HTML-фрагмент (вход по ссылке) — терминальный результат: подменяем слот.
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
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                const data = await response.json();
                if (data.result === 'success' && data.data && data.data.redirect) {
                    window.location.assign(data.data.redirect);
                    return;
                }
                if (data.message) {
                    showToast(data.message, 'danger');
                }
                restore();
                return;
            }

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            // HTML-фрагмент (вход по ссылке): терминальный результат в слоте.
            this.slotTarget.innerHTML = await response.text();
        } catch (e) {
            restore();
            showToast('Не удалось отправить. Проверьте соединение и попробуйте ещё раз.', 'danger');
        }
    }
}
