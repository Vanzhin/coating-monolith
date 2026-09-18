/**
 * Офлайн-кеш каталога покрытий в IndexedDB. Один стор наполняется синком с сервера
 * (catalog_sync_controller), из него пикер отчёта и калькуляторы /tools ищут покрытие без сети.
 *
 * Схема БД 'app-offline' v1: стор 'coatings' (keyPath id) + 'meta' (keyPath key) с версией каталога.
 * Заложено на рост: Д3 добавит свои сторы (черновики отчётов, очередь фото) бампом версии БД,
 * не ломая 'coatings'.
 *
 * Мягкая деградация: IndexedDB недоступен (приватный режим/старый вебвью) — методы возвращают
 * пусто/no-op, чтобы вызывающий (async_typeahead) ушёл в сетевой фолбэк.
 */

const DB_NAME = 'app-offline';
const DB_VERSION = 1;
const STORE = 'coatings';
const META = 'meta';
const META_KEY = 'coatings';

let dbPromise = null;

function openDb() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('IndexedDB недоступен'));

            return;
        }

        let req;
        try {
            req = indexedDB.open(DB_NAME, DB_VERSION);
        } catch (e) {
            reject(e);

            return;
        }

        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains(META)) {
                db.createObjectStore(META, { keyPath: 'key' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    }).catch((e) => {
        dbPromise = null; // дать шанс переоткрыть при следующем вызове

        throw e;
    });

    return dbPromise;
}

function reqToPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/** Сохранённая версия каталога (из последнего успешного синка) или null. */
export async function getVersion() {
    try {
        const db = await openDb();
        const rec = await reqToPromise(db.transaction(META, 'readonly').objectStore(META).get(META_KEY));

        return rec ? (rec.version ?? null) : null;
    } catch {
        return null;
    }
}

/** Весь закешированный каталог (может быть пуст). */
export async function getAll() {
    try {
        const db = await openDb();

        return await reqToPromise(db.transaction(STORE, 'readonly').objectStore(STORE).getAll());
    } catch {
        return [];
    }
}

/**
 * Локальный поиск по названию: все токены запроса как подстроки title. Ранжирование —
 * сперва title начинается с запроса. Возвращает элементы в форме, которую ждёт Tagify
 * (item + поле value = title). До limit штук.
 */
export async function search(query, limit = 50) {
    const q = (query || '').trim().toLowerCase();
    if (q === '') {
        return [];
    }

    const tokens = q.split(/\s+/).filter(Boolean);
    const all = await getAll();

    const matched = all.filter((item) => {
        const title = (item.title || '').toLowerCase();

        return tokens.every((t) => title.includes(t));
    });

    matched.sort((a, b) => {
        const at = (a.title || '').toLowerCase();
        const bt = (b.title || '').toLowerCase();
        const aStarts = at.startsWith(q) ? 0 : 1;
        const bStarts = bt.startsWith(q) ? 0 : 1;
        if (aStarts !== bStarts) {
            return aStarts - bStarts;
        }

        return at.localeCompare(bt);
    });

    return matched.slice(0, limit).map((item) => ({ ...item, value: item.title }));
}

/**
 * Полная атомарная замена каталога в одной транзакции: очистить coatings → положить все items →
 * записать version в meta. Полная замена сама убирает удалённые/переименованные покрытия.
 */
export async function replaceAll(items, version) {
    if (!Array.isArray(items)) {
        return;
    }

    try {
        const db = await openDb();
        await new Promise((resolve, reject) => {
            const transaction = db.transaction([STORE, META], 'readwrite');
            transaction.oncomplete = () => resolve();
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error);

            const store = transaction.objectStore(STORE);
            store.clear();
            for (const item of items) {
                if (item && item.id != null) {
                    store.put(item);
                }
            }
            transaction.objectStore(META).put({ key: META_KEY, version: version ?? null });
        });
    } catch {
        // офлайн/недоступно — no-op, старый кеш остаётся валидным
    }
}
