import { Controller } from '@hotwired/stimulus';
import { showToast } from '../flash_toast.js';

/**
 * Верификация канала: отправка кода (AJAX) с кулдауном (sticky в localStorage),
 * ввод 6-значного кода (только цифры) и валидация перед сабмитом. Заменяет прежний
 * inline-<script> в шаблоне; сообщения — через flash-тосты (showToast).
 *
 * Values: sendUrl — POST-эндпоинт отправки кода; cooldownKey — ключ localStorage.
 */
export default class extends Controller {
    static targets = ['channel', 'sendBtn', 'sendText', 'timer', 'token', 'submit'];
    static values = {
        sendUrl: String,
        cooldownKey: { type: String, default: 'verification_cooldown' },
    };

    connect() {
        this._left = 0;
        this._interval = null;
        this._checkStored();
        this._syncSubmit();
    }

    disconnect() {
        clearInterval(this._interval);
    }

    onChannelChange() {
        if (this.channelTarget.value) {
            this.tokenTarget.focus();
        }
    }

    onTokenInput() {
        this.tokenTarget.value = this.tokenTarget.value.replace(/\D/g, '');
        this._syncSubmit();
    }

    _syncSubmit() {
        this.submitTarget.disabled = this.tokenTarget.value.length !== 6;
    }

    onSubmit(event) {
        if (this.tokenTarget.value.length !== 6) {
            event.preventDefault();
            showToast('Введите 6-значный код', 'warning');
            this.tokenTarget.focus();
            return;
        }
        if (!this.channelTarget.value) {
            event.preventDefault();
            showToast('Выберите канал для верификации', 'warning');
        }
    }

    async send() {
        if (this._left > 0) {
            return;
        }
        const channelId = this.channelTarget.value;
        if (!channelId) {
            showToast('Выберите канал для верификации', 'warning');
            return;
        }

        this.sendBtnTarget.disabled = true;
        const original = this.sendTextTarget.textContent;
        this.sendTextTarget.textContent = 'Отправка...';

        try {
            const res = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ channel_id: channelId }),
            });
            const data = await res.json();
            const payload = data.data || data;
            if (payload.success) {
                this._startCooldown(payload.data?.cooldown_remaining || 300);
                showToast('Код отправлен. Проверьте канал связи.', 'success');
                this.tokenTarget.focus();
            } else {
                showToast(payload.message || 'Ошибка при отправке кода', 'error');
                this.sendBtnTarget.disabled = false;
                this.sendTextTarget.textContent = original;
            }
        } catch {
            showToast('Ошибка при отправке кода. Попробуйте позже.', 'error');
            this.sendBtnTarget.disabled = false;
            this.sendTextTarget.textContent = original;
        }
    }

    _startCooldown(seconds) {
        this._left = seconds;
        this._renderCooldown();
        clearInterval(this._interval);
        this._interval = setInterval(() => {
            this._left -= 1;
            if (this._left <= 0) {
                this._endCooldown();
            } else {
                this._renderCooldown();
            }
        }, 1000);
    }

    _renderCooldown() {
        const m = Math.floor(this._left / 60);
        const s = this._left % 60;
        this.timerTarget.textContent = `(${m}:${String(s).padStart(2, '0')})`;
        this.sendTextTarget.textContent = 'Отправить повторно';
        this.sendBtnTarget.disabled = true;
        try {
            window.localStorage.setItem(this.cooldownKeyValue, JSON.stringify({ expires: Date.now() + this._left * 1000 }));
        } catch { /* storage недоступен — кулдаун только в памяти */ }
    }

    _endCooldown() {
        clearInterval(this._interval);
        this._left = 0;
        this.sendBtnTarget.disabled = false;
        this.sendTextTarget.textContent = 'Отправить код верификации';
        this.timerTarget.textContent = '';
        try { window.localStorage.removeItem(this.cooldownKeyValue); } catch { /* ignore */ }
    }

    _checkStored() {
        try {
            const raw = window.localStorage.getItem(this.cooldownKeyValue);
            if (!raw) {
                return;
            }
            const { expires } = JSON.parse(raw);
            const left = Math.max(0, Math.floor((expires - Date.now()) / 1000));
            if (left > 0) {
                this._startCooldown(left);
            } else {
                window.localStorage.removeItem(this.cooldownKeyValue);
            }
        } catch { /* ignore */ }
    }
}
