/**
 * Общие помощники для ссылок-референсов документа (кросс-контекст Certificates ↔ Coatings
 * резолвится в браузере). Используются document-preview и document-references контроллерами.
 */

export const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

// Глобально: ESC или клик вне модалки закрывают ВЕСЬ стек модалок (любого уровня).
// Кнопка «Закрыть»/крестик (data-bs-dismiss) по-прежнему закрывает только свою — на неё это не влияет.
let closeAllInitialised = false;

function closeAllModals() {
    document.querySelectorAll('.modal.show').forEach((modalEl) => {
        window.bootstrap?.Modal.getInstance(modalEl)?.hide();
    });
}

function initCloseAllModals() {
    if (closeAllInitialised) {
        return;
    }
    closeAllInitialised = true;

    document.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) {
            closeAllModals();
        }
    });
    // Клик по самому .modal (вне .modal-dialog) = клик по фону/вне контента. Как в Bootstrap,
    // сверяем цель mousedown и click: выделение текста, начатое внутри диалога и отпущенное на
    // фоне, не должно закрывать стек (иначе один промах мышью схлопывает все модалки).
    let backdropMousedownTarget = null;
    document.addEventListener('mousedown', (event) => {
        backdropMousedownTarget = event.target;
    });
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (target instanceof HTMLElement
            && target === backdropMousedownTarget
            && target.classList.contains('modal')) {
            closeAllModals();
        }
    });
}

initCloseAllModals();

/**
 * Резолвит названия объектов по id через by-ids эндпоинт Coatings.
 *
 * @param {string} endpoint
 * @param {string[]} ids
 * @returns {Promise<Map<string, string>>} id → title
 */
export async function fetchTitlesByIds(endpoint, ids) {
    const map = new Map();
    try {
        const qs = ids.map(id => `ids[]=${encodeURIComponent(id)}`).join('&');
        const response = await fetch(`${endpoint}?${qs}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) {
            return map;
        }
        const json = await response.json();
        const items = json.data?.items ?? json.items ?? [];
        items.forEach(item => map.set(item.id, item.title));
    } catch {
        // Сеть недоступна — пустая карта, метки останутся как есть.
    }
    return map;
}

/**
 * Фетчит серверный HTML-фрагмент модалки и показывает его поверх стека.
 *
 * Модалку кладём отдельным узлом прямо в <body> (НЕ вложенно в текущую модалку) — иначе ESC и
 * клик вне всплывали бы в нижние модалки и закрывали весь стек; сиблинги в body этого не делают.
 * Каждый следующий уровень смещаем вниз, чтобы был виден край нижней модалки — пользователь
 * понимает глубину. После закрытия узел удаляется.
 *
 * @param {string} url
 * @param {(modalEl: HTMLElement) => void} [onModal] хук до показа (напр. проставить data-атрибут)
 */
export async function openFragmentModal(url, onModal = null) {
    const response = await fetch(url, { headers: { 'Accept': 'text/html' } });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const template = document.createElement('template');
    template.innerHTML = (await response.text()).trim();
    const modalEl = template.content.querySelector('.modal');
    if (!modalEl) {
        return;
    }

    document.body.appendChild(modalEl);

    // Смещение по глубине стека (сколько модалок уже открыто) — видно край нижней.
    const depth = document.querySelectorAll('.modal.show').length;
    if (depth > 0) {
        const dialog = modalEl.querySelector('.modal-dialog');
        if (dialog) {
            dialog.style.marginTop = `${1.75 + depth * 1.5}rem`;
        }
    }

    if (onModal) {
        onModal(modalEl);
    }

    modalEl.addEventListener('hidden.bs.modal', () => modalEl.remove(), { once: true });
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

/**
 * Открывает превью объекта: подставляет id в шаблон URL и показывает фрагмент-модалку.
 * Единая точка для document-preview / document-references / *-preview-loader.
 *
 * @param {string} urlTemplate шаблон URL с плейсхолдером id (ZERO_UUID)
 * @param {string} id
 */
export async function openReferencePreview(urlTemplate, id) {
    try {
        await openFragmentModal(urlTemplate.replace(ZERO_UUID, id));
    } catch {
        alert('Не удалось загрузить превью. Попробуйте ещё раз.');
    }
}
