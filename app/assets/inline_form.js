// Инлайн-отправка формы без ухода со страницы. Шлёт fetch (X-Requested-With) и разбирает конверт
// ResponseListener { result, status, data, message }:
//   - { redirect } — успех с переходом (success + data.redirect);
//   - { message }  — провал, показать сообщение (result:error);
//   - { html }     — сервер вернул HTML-фрагмент (не JSON) — терминальный результат для слота.
// Сетевая ошибка / не-ok не-JSON — бросает (ловит вызывающий и показывает общий тост).
// Переиспользуется формами входа/регистрации (inline-auth) и верификации канала (channel-verify),
// чтобы разбор ответа не дублировался.
export async function submitInlineForm(form) {
    const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
        const data = await response.json();
        if (data.result === 'success' && data.data && data.data.redirect) {
            return { redirect: data.data.redirect };
        }

        return { message: data.message || null };
    }

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return { html: await response.text() };
}
