import { Controller } from '@hotwired/stimulus';

/**
 * Догружает следующие страницы списка покрытий вместо page-based pager'a.
 *  - IntersectionObserver на sentinel-элементе автоматически стреляет,
 *    когда он появляется в viewport (юзер прокрутил до низа).
 *  - Кнопка «Показать ещё» дублирует то же действие для тех кто хочет
 *    кликать руками.
 *
 * Backend отдаёт голый partial по URL с флагом ?partial=1. Всё что нужно
 * серверу для URL — уже собрано в baseUrl (сохраняет search, tagIds,
 * manufacturerIds и т.д.). Мы только добавляем &page=N&partial=1.
 */
export default class extends Controller {
    static targets = ['cards', 'sentinel', 'button', 'buttonLabel'];

    static values = {
        nextPage:    Number,   // страница которую загружать следующей (2 при старте)
        totalPages:  Number,   // сколько всего страниц по текущему фильтру
        baseUrl:     String,   // /cabinet/coating/coating/list?search=X&tagIds[]=Y  (уже с ? или &)
    };

    connect() {
        this._loading = false;
        if (this._hasMore()) {
            this._observer = new IntersectionObserver(
                (entries) => {
                    for (const e of entries) {
                        if (e.isIntersecting) this.loadNext();
                    }
                },
                { rootMargin: '200px' },  // старт до того как sentinel окажется на экране
            );
            this._observer.observe(this.sentinelTarget);
        } else {
            this._done();
        }
    }

    disconnect() {
        if (this._observer) this._observer.disconnect();
    }

    async loadNext() {
        if (this._loading || !this._hasMore()) return;
        this._loading = true;
        this._setButtonLoading(true);

        const url = this._urlForPage(this.nextPageValue);
        try {
            const resp = await fetch(url, { headers: { 'Accept': 'text/html' } });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const html = await resp.text();
            const trimmed = html.trim();
            if (trimmed !== '') {
                this.cardsTarget.insertAdjacentHTML('beforeend', trimmed);
            }
            this.nextPageValue = this.nextPageValue + 1;

            if (!this._hasMore()) {
                this._done();
            }
        } catch (err) {
            this._setButtonError();
        } finally {
            this._loading = false;
            if (this._hasMore()) this._setButtonLoading(false);
        }
    }

    _hasMore() {
        return this.nextPageValue <= this.totalPagesValue;
    }

    _urlForPage(page) {
        const sep = this.baseUrlValue.includes('?') ? '&' : '?';
        return `${this.baseUrlValue}${sep}page=${page}&partial=1`;
    }

    _setButtonLoading(loading) {
        if (!this.hasButtonTarget) return;
        this.buttonTarget.disabled = loading;
        if (this.hasButtonLabelTarget) {
            this.buttonLabelTarget.textContent = loading ? 'Загружаем…' : 'Показать ещё';
        }
    }

    _setButtonError() {
        if (this.hasButtonLabelTarget) {
            this.buttonLabelTarget.textContent = 'Ошибка — попробуйте ещё раз';
        }
    }

    _done() {
        // Больше страниц нет — прячем кнопку и отключаем observer.
        if (this._observer) this._observer.disconnect();
        if (this.hasButtonTarget) {
            this.buttonTarget.closest('div[data-infinite-list-target="loadMoreWrapper"], .text-center')?.classList.add('d-none');
        }
    }
}
