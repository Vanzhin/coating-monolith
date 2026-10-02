import { Controller } from '@hotwired/stimulus';

/**
 * Двухфазная загрузка файла: при выборе шлём его на /cabinet/file/stage (POST files), полученный uuid
 * кладём в скрытое поле — при сабмите формы до хендлера доходит только uuid, промоут делает команда.
 */
export default class extends Controller {
    static values = { stageUrl: String };
    static targets = ['hidden', 'name'];

    async upload(event) {
        const file = event.target.files[0];
        if (!file) {
            return;
        }
        const body = new FormData();
        body.append('files', file);
        try {
            const res = await fetch(this.stageUrlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const json = await res.json();
            const payload = json.data ?? json; // глобальный ResponseListener оборачивает успех в {data}
            if (payload.files && payload.files[0]) {
                this.hiddenTarget.value = payload.files[0].uuid;
                if (this.hasNameTarget) {
                    this.nameTarget.textContent = payload.files[0].name;
                }
            } else if (json.message && this.hasNameTarget) {
                this.nameTarget.textContent = json.message; // ошибка: message в конверте верхнего уровня
            }
        } catch (e) {
            if (this.hasNameTarget) {
                this.nameTarget.textContent = 'Ошибка загрузки';
            }
        }
    }
}
