import { Controller } from '@hotwired/stimulus';

/**
 * Убирает одно покрытие из compare-набора.
 *  - Читает текущие `?ids=` из URL, удаляет свой idValue.
 *  - Синхронизирует localStorage (ключ storageKeyValue), чтобы tray на
 *    странице списка не показывал removed-покрытие как «в сравнении».
 *  - Если после удаления осталось >= 2 → редирект на compare?ids=new,
 *    иначе → на список (CompareAction сам редирект-flow не даст открыть).
 */
export default class extends Controller {
    static values = {
        id:         String,
        listUrl:    String,
        compareUrl: { type: String, default: '/cabinet/coating/coating/compare' },
        storageKey: { type: String, default: 'compare:Coating' },
    };

    remove(event) {
        event.preventDefault();

        const params = new URLSearchParams(window.location.search);
        const currentIds = (params.get('ids') || '')
            .split(',').map(s => s.trim()).filter(Boolean);
        const remaining = currentIds.filter(x => x !== this.idValue);

        // localStorage — best-effort; блокировка (private mode etc.) не должна
        // останавливать навигацию.
        try {
            const rawStored = window.localStorage.getItem(this.storageKeyValue);
            const stored = rawStored ? JSON.parse(rawStored) : [];
            const newStored = stored.filter(x => x !== this.idValue);
            window.localStorage.setItem(this.storageKeyValue, JSON.stringify(newStored));
        } catch (e) {
            // сториджа нет / disabled — идём дальше.
        }

        if (remaining.length < 2) {
            window.location.href = this.listUrlValue;
        } else {
            window.location.href = `${this.compareUrlValue}?ids=${remaining.join(',')}`;
        }
    }
}
