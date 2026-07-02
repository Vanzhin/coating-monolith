import { Controller } from '@hotwired/stimulus';

/**
 * Reusable infinite-scroll контроллер. Работает в паре с partial'ом
 * templates/components/infinite_list.html.twig — там же и CSS-анимация
 * плавного появления.
 *
 *  - IntersectionObserver на sentinel — авто-догрузка при подходе к концу.
 *  - Кнопка «Показать ещё» — ручной триггер той же процедуры. Внутри кнопки
 *    Bootstrap-спиннер, показывается на время in-flight запроса.
 *  - Backend должен отдавать голый partial по URL с ?partial=1.
 *    Всё что нужно серверу для фильтров — уже в baseUrl.
 */
export default class extends Controller {
    static targets = ['cards', 'sentinel', 'button', 'buttonLabel', 'spinner', 'loadMoreWrapper'];

    static values = {
        nextPage:   Number,   // страница на следующую догрузку (обычно 2)
        totalPages: Number,   // сколько всего страниц по текущему фильтру
        baseUrl:    String,   // URL со всеми текущими filter-params (Stimulus добавляет &page=N&partial=1)
    };

    connect() {
        this._loading = false;
        this._originalLabel = this.hasButtonLabelTarget
            ? this.buttonLabelTarget.textContent.trim()
            : 'Показать ещё';

        if (this._hasMore()) {
            this._observer = new IntersectionObserver(
                (entries) => {
                    for (const e of entries) {
                        if (e.isIntersecting) this.loadNext();
                    }
                },
                { rootMargin: '200px' },
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
        this._setLoading(true);

        const url = this._urlForPage(this.nextPageValue);
        try {
            const resp = await fetch(url, { headers: { 'Accept': 'text/html' } });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const html = (await resp.text()).trim();

            if (html === '') {
                // Backend ничего не отдал — данных больше нет, независимо от totalPages
                // (счёт мог устареть между рендером первой страницы и догрузкой).
                this._done();
                return;
            }

            this._appendBatch(html);
            this.nextPageValue = this.nextPageValue + 1;
            if (!this._hasMore()) this._done();
        } catch (err) {
            this._setError();
        } finally {
            this._loading = false;
            if (this._hasMore()) this._setLoading(false);
        }
    }

    _hasMore() {
        return this.nextPageValue <= this.totalPagesValue;
    }

    _urlForPage(page) {
        const sep = this.baseUrlValue.includes('?') ? '&' : '?';
        return `${this.baseUrlValue}${sep}page=${page}&partial=1`;
    }

    /**
     * Вставляет HTML-batch через DocumentFragment, каждому корневому элементу
     * навешивает класс .infinite-list-appear — CSS-анимация из partial'a
     * даёт плавное появление.
     */
    _appendBatch(html) {
        const fragment = document.createRange().createContextualFragment(html);
        for (const node of fragment.children) {
            if (node.nodeType === 1) {
                node.classList.add('infinite-list-appear');
            }
        }
        this.cardsTarget.appendChild(fragment);
    }

    _setLoading(loading) {
        if (this.hasButtonTarget) this.buttonTarget.disabled = loading;
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.toggle('d-none', !loading);
        if (this.hasButtonLabelTarget) {
            this.buttonLabelTarget.textContent = loading ? 'Загружаем…' : this._originalLabel;
        }
    }

    _setError() {
        if (this.hasButtonLabelTarget) {
            this.buttonLabelTarget.textContent = 'Ошибка — попробуйте ещё раз';
        }
        if (this.hasSpinnerTarget) this.spinnerTarget.classList.add('d-none');
    }

    _done() {
        if (this._observer) this._observer.disconnect();
        if (this.hasLoadMoreWrapperTarget) {
            // Плавное исчезновение — CSS animation задаёт fade-out, потом d-none
            // окончательно убирает из потока.
            this.loadMoreWrapperTarget.classList.add('infinite-list-hide');
            setTimeout(() => {
                this.loadMoreWrapperTarget.classList.add('d-none');
            }, 260);
        }
    }
}
