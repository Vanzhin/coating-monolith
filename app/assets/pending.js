/**
 * Общий guard для «серверных» кнопок/триггеров: пока асинхронное действие в полёте,
 * элемент гасится и повторные клики игнорируются. Так один клик по «превью покрытия»
 * при тормозящей сети не плодит несколько одинаковых модалок.
 *
 * Идемпотентен к типу элемента: <button>/<input> гасим через disabled, любой другой
 * триггер (карточка/чип/строка) — через aria-disabled + .is-pending (pointer-events:none).
 * Восстановление гарантировано в finally, в т.ч. если asyncFn бросил.
 *
 * @template T
 * @param {HTMLElement} el триггер (обычно event.currentTarget)
 * @param {() => Promise<T>} asyncFn серверное действие
 * @returns {Promise<T|undefined>} результат asyncFn, либо undefined если клик проигнорирован
 */
export async function withPending(el, asyncFn) {
    if (!el || '1' === el.dataset.pending) {
        return undefined;
    }

    el.dataset.pending = '1';
    const native = 'BUTTON' === el.tagName || 'INPUT' === el.tagName;
    if (native) {
        el.disabled = true;
    } else {
        el.setAttribute('aria-disabled', 'true');
    }
    el.setAttribute('aria-busy', 'true');
    el.classList.add('is-pending');

    try {
        return await asyncFn();
    } finally {
        delete el.dataset.pending;
        if (native) {
            el.disabled = false;
        } else {
            el.removeAttribute('aria-disabled');
        }
        el.removeAttribute('aria-busy');
        el.classList.remove('is-pending');
    }
}
