/**
 * Общие помощники для ссылок-референсов документа (кросс-контекст Certificates ↔ Coatings
 * резолвится в браузере). Используются document-preview и document-references контроллерами.
 */

export const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

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
 * Фетчит серверный HTML-фрагмент модалки и показывает его поверх (стек Bootstrap).
 * Контейнер очищается после закрытия. Бросает при HTTP-ошибке — вызывающий решает, что показать.
 *
 * @param {HTMLElement} host
 * @param {string} url
 */
export async function openFragmentModal(host, url) {
    const response = await fetch(url, { headers: { 'Accept': 'text/html' } });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    host.innerHTML = await response.text();

    const modalEl = host.querySelector('.modal');
    if (!modalEl) {
        return;
    }
    modalEl.addEventListener('hidden.bs.modal', () => { host.innerHTML = ''; }, { once: true });
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

/**
 * Открывает превью объекта-референса: подставляет id в шаблон URL и показывает фрагмент-модалку.
 * При ошибке чистит host и показывает alert. Единая точка для document-preview/document-references.
 *
 * @param {HTMLElement} host
 * @param {string} urlTemplate шаблон URL с плейсхолдером id (ZERO_UUID)
 * @param {string} id
 */
export async function openReferencePreview(host, urlTemplate, id) {
    try {
        await openFragmentModal(host, urlTemplate.replace(ZERO_UUID, id));
    } catch {
        host.innerHTML = '';
        alert('Не удалось загрузить превью. Попробуйте ещё раз.');
    }
}
