import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Управляет формой редактирования покрытия:
 *  - модалкой длительности (#durationModal) — открытие, заполнение, сохранение,
 *  - универсальным добавлением/удалением строк во всех температурно-зависимых
 *    подсекциях (data-series="dryToTouch|fullCure|recoating-{nodeId}"),
 *  - динамическим добавлением/удалением env-вкладок и base-правил
 *    в дереве интервалов перекрытия.
 */
export default class extends Controller {
    static targets = [
        'modal', 'modalLabel',
        'modalDays', 'modalHours', 'modalMinutes',
        'calcBtn',
        'kindGroup', 'kindRadio', 'kindUnlimitedLabel', 'kindUnknownLabel', 'durationFields',
    ];

    connect() {
        this.currentName = null;
        const modalEl = document.getElementById('durationModal');
        if (modalEl) {
            this._onShow = this.onShowDurationModal.bind(this);
            modalEl.addEventListener('show.bs.modal', this._onShow);
        }
    }

    disconnect() {
        const modalEl = document.getElementById('durationModal');
        if (modalEl && this._onShow) {
            modalEl.removeEventListener('show.bs.modal', this._onShow);
        }
    }

    // --- Модалка ---

    onShowDurationModal(event) {
        const button = event.relatedTarget;
        if (!button) return;
        this.currentName = button.dataset.targetName;
        const required = button.dataset.required === '1';
        const allowUnlimited = button.dataset.allowUnlimited === '1';
        const currentKind = button.dataset.currentKind || 'duration';

        const tr = button.closest('tr');
        const tempInput = tr?.querySelector('input[name$="[temperature_at]"]');
        const labelTpl = button.getAttribute('data-target-label') || 'Длительность';
        this.modalLabelTarget.textContent = tempInput
            ? labelTpl.replace(/\+-?\d+\s*°C/, '+' + tempInput.value + ' °C')
            : labelTpl;

        // Скрываем недоступные radio для данного контекста.
        if (this.hasKindUnlimitedLabelTarget) {
            this.kindUnlimitedLabelTarget.style.display = allowUnlimited ? '' : 'none';
            this.kindUnlimitedLabelTarget.previousElementSibling.style.display = allowUnlimited ? '' : 'none';
        }
        if (this.hasKindUnknownLabelTarget) {
            // Для required (min/сушка) скрываем «нет данных» — должно быть введено duration.
            const showUnknown = !required;
            this.kindUnknownLabelTarget.style.display = showUnknown ? '' : 'none';
            this.kindUnknownLabelTarget.previousElementSibling.style.display = showUnknown ? '' : 'none';
        }

        // Подсветить текущий kind.
        const safeKind = (currentKind === 'unlimited' && allowUnlimited)
            || (currentKind === 'unknown' && !required)
            || currentKind === 'duration'
            ? currentKind
            : 'duration';

        this.kindRadioTargets.forEach(r => {
            r.checked = r.value === safeKind;
        });

        // Подгрузить значения days/hours/minutes из текущей строки.
        this.modalDaysTarget.value    = this._readHidden(this.currentName, 'days');
        this.modalHoursTarget.value   = this._readHidden(this.currentName, 'hours');
        this.modalMinutesTarget.value = this._readHidden(this.currentName, 'minutes');

        if (this.hasCalcBtnTarget) {
            this.calcBtnTarget.disabled = tr
                ? this._gatherSiblingPoints(tr).length < 2
                : true;
        }

        this._applyKindVisibility(safeKind);
    }

    onKindChange() {
        const kind = this._currentRadioKind();
        this._applyKindVisibility(kind);
        if (kind !== 'duration') {
            this.modalDaysTarget.value = 0;
            this.modalHoursTarget.value = 0;
            this.modalMinutesTarget.value = 0;
        }
    }

    _applyKindVisibility(kind) {
        if (this.hasDurationFieldsTarget) {
            this.durationFieldsTarget.style.display = kind === 'duration' ? '' : 'none';
        }
    }

    _currentRadioKind() {
        const checked = this.kindRadioTargets.find(r => r.checked);
        return checked ? checked.value : 'duration';
    }

    _readHidden(name, key) {
        const el = this.element.querySelector(`input[type="hidden"][name="${name}[${key}]"]`);
        return el ? el.value : 0;
    }

    /** Синхронизирует температуру в hidden-mirror строки (для парных серий). */
    syncTemperature(event) {
        const input = event.currentTarget;
        const i = input.dataset.row;
        const mirror = this.element.querySelector(`input[data-temp-mirror="${i}"]`);
        if (mirror) mirror.value = input.value;
    }

    saveDuration() {
        if (!this.currentName) return;

        const kind = this._currentRadioKind();
        const isDuration = kind === 'duration';

        this._hidden(this.currentName, 'days').value    = isDuration ? this._intVal(this.modalDaysTarget) : 0;
        this._hidden(this.currentName, 'hours').value   = isDuration ? this._intVal(this.modalHoursTarget) : 0;
        this._hidden(this.currentName, 'minutes').value = isDuration ? this._intVal(this.modalMinutesTarget) : 0;

        // Хидден поле [kind] должно существовать (создаётся макросом). Если нет — создаём.
        let kindHidden = this._hidden(this.currentName, 'kind');
        if (!kindHidden) {
            kindHidden = document.createElement('input');
            kindHidden.type = 'hidden';
            kindHidden.name = `${this.currentName}[kind]`;
            this.element.querySelector(`button[data-target-name="${this.currentName}"]`)?.parentElement?.appendChild(kindHidden);
        }
        kindHidden.value = kind;

        const btn = this.element.querySelector(`button[data-target-name="${this.currentName}"]`);
        if (btn) {
            btn.dataset.currentKind = kind;
            this._refreshButton(btn);
        }

        Modal.getOrCreateInstance(this.modalTarget).hide();
    }

    clearDuration() {
        this.modalDaysTarget.value    = 0;
        this.modalHoursTarget.value   = 0;
        this.modalMinutesTarget.value = 0;
    }

    /** «Рассчитать» — линейная интерполяция через бэк (/cabinet/coating/series/interpolate). */
    async calculateDuration() {
        if (!this.currentName) return;
        const triggerBtn = this.element.querySelector(`button[data-target-name="${this.currentName}"]`);
        const tr = triggerBtn?.closest('tr');
        if (!tr) return;

        const targetTemp = parseInt(tr.querySelector('input[name$="[temperature_at]"]')?.value || 0, 10);
        const points = this._gatherSiblingPoints(tr);
        if (points.length < 2) return;

        this.calcBtnTarget.disabled = true;
        try {
            const response = await fetch('/cabinet/coating/series/interpolate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ targetTemperature: targetTemp, points }),
                credentials: 'same-origin',
            });
            const envelope = await response.json();
            if (envelope.result !== 'success' || !envelope.data) {
                alert(envelope.message || 'Не удалось рассчитать значение.');
                return;
            }
            const decomposed = this._decompose(envelope.data.minutes);
            this.modalDaysTarget.value    = decomposed.days;
            this.modalHoursTarget.value   = decomposed.hours;
            this.modalMinutesTarget.value = decomposed.minutes;
        } catch (e) {
            alert('Ошибка обращения к серверу: ' + e.message);
        } finally {
            this.calcBtnTarget.disabled = false;
        }
    }

    // --- Валидация перед submit ---

    /**
     * Блокирует submit, если хоть одна min-точка интервала перекрытия не заполнена.
     * Max-точки могут оставаться нулевыми (означают «без ограничения»).
     */
    validateBeforeSubmit(event) {
        const root = this.element.querySelector('[data-recoating-root]');
        if (!root) return;
        const tbodies = root.querySelectorAll('tbody[data-series^="recoating-"]');
        const emptyTemps = [];
        for (const tbody of tbodies) {
            const minPrefix = tbody.dataset.minPrefix;
            if (!minPrefix) continue;
            for (const tr of tbody.querySelectorAll('tr')) {
                const d = parseInt(tr.querySelector(`input[type=hidden][name^="${minPrefix}["][name$="[days]"]`)?.value || 0, 10);
                const h = parseInt(tr.querySelector(`input[type=hidden][name^="${minPrefix}["][name$="[hours]"]`)?.value || 0, 10);
                const m = parseInt(tr.querySelector(`input[type=hidden][name^="${minPrefix}["][name$="[minutes]"]`)?.value || 0, 10);
                if (d * 1440 + h * 60 + m > 0) continue;
                const temp = tr.querySelector(`input[name^="${minPrefix}["][name$="[temperature_at]"]`)?.value ?? '?';
                emptyTemps.push(`+${temp}°C`);
            }
        }
        if (emptyTemps.length > 0) {
            event.preventDefault();
            alert(`Заполните минимальный интервал перекрытия для точек: ${emptyTemps.join(', ')} (или удалите эти строки).`);
        }
    }

    // --- Добавление / удаление строк ---

    addRow(event) {
        const series = event.params?.tbody ?? event.currentTarget.dataset.series;
        if (!series) return;
        const tbody = this.element.querySelector(`tbody[data-series="${series}"]`);
        if (!tbody) return;
        const i = tbody.children.length;
        const temp = this._nextFreeTemperature(tbody);
        const tr = document.createElement('tr');

        if (series.startsWith('recoating-')) {
            const minPrefix = tbody.dataset.minPrefix;
            const maxPrefix = tbody.dataset.maxPrefix;
            const nodeId = series.replace(/^recoating-/, '');
            tr.innerHTML = this._pairedRecoatingRow(minPrefix, maxPrefix, nodeId, i, temp);
        } else {
            tr.innerHTML = this._singleSeriesRow(series, i, temp);
        }
        tbody.appendChild(tr);
    }

    /**
     * Возвращает температуру, отсутствующую в текущем tbody.
     * Если 20°C свободно — берём её, иначе max(существующих) + 5°C.
     */
    _nextFreeTemperature(tbody) {
        const used = new Set();
        tbody.querySelectorAll('input[name$="[temperature_at]"]').forEach(el => {
            const v = parseInt(el.value, 10);
            if (!Number.isNaN(v)) used.add(v);
        });
        if (used.size === 0 || !used.has(20)) return 20;
        return Math.max(...used) + 5;
    }

    removeRow(event) {
        const tr = event.currentTarget.closest('tr');
        const tbody = tr?.parentElement;
        if (!tbody) return;
        const required = tbody.dataset.seriesRequired !== '0';
        if (required && tbody.children.length <= 1) {
            alert('Нужна хотя бы одна точка.');
            return;
        }
        tr.remove();
    }

    // --- Среды (env-вкладки) ---

    addEnv(event) {
        const envKey = event.params.env;
        if (!envKey) return;
        const root = this.element.querySelector('[data-recoating-root]');
        if (!root) return;

        const tabsUl = root.querySelector('[data-recoating-tabs]');
        const tabContent = root.querySelector('.tab-content');

        const envLabels = { atmospheric: 'Атмосферная', immersion: 'Погружение', special: 'Спец среды' };
        const label = envLabels[envKey] || envKey;

        // Создать tab-кнопку (структура совпадает с Twig: close — sibling nav-link).
        const tabLi = document.createElement('li');
        tabLi.className = 'nav-item';
        tabLi.setAttribute('role', 'presentation');
        tabLi.setAttribute('data-env-tab', envKey);
        tabLi.innerHTML = `
            <button class="nav-link d-inline-flex align-items-center gap-2"
                    data-bs-toggle="tab" data-bs-target="#recoating-pane-${envKey}"
                    type="button" role="tab">
                <span>${label}</span>
                <span role="button" tabindex="0"
                      class="text-body-secondary d-inline-flex align-items-center"
                      data-action="click->coating-form#removeEnv"
                      data-coating-form-env-param="${envKey}"
                      title="Удалить ветку"
                      aria-label="Удалить ветку">
                    <i class="bi bi-x-lg"></i>
                </span>
            </button>`;
        const addEnvLi = root.querySelector('[data-recoating-add-env]');
        tabsUl.insertBefore(tabLi, addEnvLi);

        // Создать pane.
        const paneDiv = document.createElement('div');
        paneDiv.className = 'tab-pane fade';
        paneDiv.id = `recoating-pane-${envKey}`;
        paneDiv.setAttribute('role', 'tabpanel');
        paneDiv.innerHTML = this._envPaneHTML(envKey);
        tabContent.appendChild(paneDiv);

        // Скрыть выбранную среду в dropdown.
        const opt = root.querySelector(`[data-env-option="${envKey}"]`);
        if (opt) opt.closest('li').style.display = 'none';

        // Активировать новую вкладку через Bootstrap.
        const Tab = window.bootstrap?.Tab;
        if (Tab) new Tab(tabLi.querySelector('button.nav-link')).show();
    }

    removeEnv(event) {
        event.stopPropagation();
        const envKey = event.params.env;
        if (!envKey) return;
        const root = this.element.querySelector('[data-recoating-root]');
        if (!root) return;
        const tabLi = root.querySelector(`[data-env-tab="${envKey}"]`);
        const pane = root.querySelector(`#recoating-pane-${envKey}`);
        const wasActive = tabLi?.querySelector('.nav-link.active');
        tabLi?.remove();
        pane?.remove();

        // Вернуть среду в dropdown.
        const opt = root.querySelector(`[data-env-option="${envKey}"]`);
        if (opt) opt.closest('li').style.display = '';

        // Если удалили активную — переключиться на «Общее».
        if (wasActive) {
            const Tab = window.bootstrap?.Tab;
            const rootBtn = root.querySelector('button[data-bs-target="#recoating-pane-root"]');
            if (Tab && rootBtn) new Tab(rootBtn).show();
        }
    }

    // --- Основания (base-правила) ---

    addBase(event) {
        const envKey = event.params.env;
        const baseKey = event.params.base;
        if (!envKey || !baseKey) return;
        const pane = this.element.querySelector(`#recoating-pane-${envKey} [data-recoating-node]`);
        if (!pane) return;

        // Проверим, что эту основу ещё не добавили.
        const existing = pane.parentElement.querySelector(`[data-recoating-node="${envKey}-${baseKey}"]`);
        if (existing) return;

        const minPrefix = `minRecoatingInterval[branches][${envKey}][branches][${baseKey}][default][points]`;
        const maxPrefix = `maxRecoatingInterval[branches][${envKey}][branches][${baseKey}][default][points]`;
        const nodeId = `${envKey}-${baseKey}`;
        const rowHTML = this._pairedRecoatingRow(minPrefix, maxPrefix, nodeId, 0);
        const baseLabel = this._baseLabelsFromRoot()[baseKey] || baseKey;

        const block = document.createElement('div');
        // Тертиарная карточка + reset --table-rows-cell-bg на body-bg,
        // чтобы строки таблицы были белыми на сером фоне вложенной карточки.
        block.className = 'p-3 mt-3 rounded-3 bg-body-tertiary';
        block.setAttribute('style', '--table-rows-cell-bg: var(--bs-body-bg);');
        block.setAttribute('data-recoating-node', nodeId);
        block.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-semibold mb-0">Основа: ${baseLabel}</h6>
                <button type="button" class="btn-close"
                        data-action="click->coating-form#removeBase"
                        data-coating-form-env-param="${envKey}"
                        data-coating-form-base-param="${baseKey}"
                        title="Удалить правило для основы"
                        aria-label="Удалить правило для основы"></button>
            </div>
            <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-rows">
                <thead><tr>
                    <th class="text-muted fw-normal" style="width: 160px;">Температура</th>
                    <th class="text-muted fw-normal">Минимальный</th>
                    <th class="text-muted fw-normal">Максимальный</th>
                    <th style="width: 60px;"></th>
                </tr></thead>
                <tbody data-series="recoating-${nodeId}" data-min-prefix="${minPrefix}" data-max-prefix="${maxPrefix}">
                    <tr>${rowHTML}</tr>
                </tbody>
            </table>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-action="click->coating-form#addRow"
                        data-coating-form-tbody-param="recoating-${nodeId}">+ Точка</button>
            </div>`;
        pane.appendChild(block);
    }

    removeBase(event) {
        const envKey = event.params.env;
        const baseKey = event.params.base;
        if (!envKey || !baseKey) return;
        const block = this.element.querySelector(`[data-recoating-node="${envKey}-${baseKey}"]`);
        block?.remove();
    }

    // --- Утилиты ---

    _hidden(name, suffix) {
        return this.element.querySelector(`input[type=hidden][name="${name}[${suffix}]"]`);
    }

    _intVal(input) {
        return parseInt(input.value || 0, 10);
    }

    /**
     * Возвращает базовые префиксы для строки, читая data-атрибуты окружающего tbody.
     * @returns {{ min: string|null, max: string|null }|null}
     */
    _seriesPrefixFromRow(tr) {
        const tbody = tr?.closest('tbody[data-series]');
        if (!tbody) return null;
        return {
            min: tbody.dataset.minPrefix || null,
            max: tbody.dataset.maxPrefix || null,
        };
    }

    /**
     * Собирает заполненные точки из соседних строк той же tbody.
     * Определяет нужную серию (min или max) по this.currentName.
     */
    _gatherSiblingPoints(currentTr) {
        const tbody = currentTr?.closest('tbody[data-series]');
        if (!tbody) return [];
        const series = tbody.dataset.series ?? '';
        const isRecoating = series.startsWith('recoating-');
        const minPrefix = tbody.dataset.minPrefix ?? (isRecoating ? null : series);
        const maxPrefix = tbody.dataset.maxPrefix ?? null;
        const seriesPrefix = (maxPrefix && this.currentName?.startsWith(maxPrefix)) ? maxPrefix : minPrefix;
        if (!seriesPrefix) return [];

        const points = [];
        for (const otherTr of tbody.querySelectorAll('tr')) {
            if (otherTr === currentTr) continue;
            const tempInput = otherTr.querySelector(`input[name^="${seriesPrefix}["][name$="[temperature_at]"]`);
            if (!tempInput) continue;
            const probe = otherTr.querySelector(`input[type=hidden][name^="${seriesPrefix}["][name$="[days]"]`);
            if (!probe) continue;
            const base = probe.name.replace(/\[days\]$/, '');
            const d = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[days]"]`)?.value   || 0, 10);
            const h = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[hours]"]`)?.value  || 0, 10);
            const m = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[minutes]"]`)?.value || 0, 10);
            const total = d * 1440 + h * 60 + m;
            if (total === 0) continue;
            points.push({ temperature_at: parseInt(tempInput.value || 0, 10), minutes: total });
        }
        return points;
    }

    _refreshButton(btn) {
        const kind = btn.dataset.currentKind || 'duration';
        btn.classList.toggle('btn-outline-primary', kind === 'duration');
        btn.classList.toggle('btn-outline-secondary', kind !== 'duration');
        btn.classList.toggle('text-muted', kind !== 'duration');

        if (kind === 'duration') {
            const d = parseInt(this._readHidden(btn.dataset.targetName, 'days'), 10) || 0;
            const h = parseInt(this._readHidden(btn.dataset.targetName, 'hours'), 10) || 0;
            const m = parseInt(this._readHidden(btn.dataset.targetName, 'minutes'), 10) || 0;
            const totalMin = d * 1440 + h * 60 + m;
            btn.innerHTML = this._formatMinutesShort(totalMin);
        } else if (kind === 'unlimited') {
            btn.innerHTML = '<i class="bi bi-infinity"></i> без ограничения';
        } else {
            btn.innerHTML = '<i class="bi bi-pencil"></i> нет данных';
        }
    }

    _formatMinutesShort(totalMinutes) {
        if (totalMinutes <= 0) return '0 мин';
        const d = Math.floor(totalMinutes / 1440);
        const h = Math.floor((totalMinutes - d * 1440) / 60);
        const m = totalMinutes - d * 1440 - h * 60;
        const parts = [];
        if (d) parts.push(`${d} д`);
        if (h) parts.push(`${h} ч`);
        if (m) parts.push(`${m} мин`);
        return parts.length ? parts.join(' ') : '0 мин';
    }

    _format(totalMinutes) {
        const days = Math.floor(totalMinutes / 1440);
        let rem = totalMinutes - days * 1440;
        const hours = Math.floor(rem / 60);
        const minutes = rem - hours * 60;
        const parts = [];
        if (days > 0)    parts.push(days + ' д');
        if (hours > 0)   parts.push(hours + ' ч');
        if (minutes > 0) parts.push(minutes + ' мин');
        return parts.slice(0, 2).join(' ');
    }

    _decompose(totalMinutes) {
        const days = Math.floor(totalMinutes / 1440);
        let rem = totalMinutes - days * 1440;
        const hours = Math.floor(rem / 60);
        const minutes = rem - hours * 60;
        return { days, hours, minutes };
    }

    _singleSeriesRow(series, i, temp = 20) {
        const labels = {
            dryToTouch: 'Сухой на отлип',
            fullCure:   'Полное отверждение',
        };
        const label = labels[series] || series;
        const base = `${series}[${i}]`;
        return `
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="${base}[temperature_at]" value="${temp}"
                           class="form-control" min="-50" max="100" required>
                    <span class="input-group-text">°C</span>
                </div>
            </td>
            <td>
                <input type="hidden" name="${base}[days]" value="0">
                <input type="hidden" name="${base}[hours]" value="0">
                <input type="hidden" name="${base}[minutes]" value="0">
                <input type="hidden" name="${base}[kind]" value="duration">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${base}"
                        data-target-label="${label} при +${temp}°C"
                        data-required="1"
                        data-allow-unlimited="0"
                        data-current-kind="duration">
                    <i class="bi bi-pencil"></i> не задано
                </button>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-action="click->coating-form#removeRow" title="Удалить точку">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
    }

    _pairedRecoatingRow(minPrefix, maxPrefix, nodeId, i, temp = 20) {
        const minBase = `${minPrefix}[${i}]`;
        const maxBase = `${maxPrefix}[${i}]`;
        const rowId = `${nodeId}-${i}`;
        return `
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="${minBase}[temperature_at]" value="${temp}"
                           class="form-control" data-row="${rowId}"
                           data-action="input->coating-form#syncTemperature"
                           min="-50" max="100" required>
                    <span class="input-group-text">°C</span>
                </div>
                <input type="hidden" name="${maxBase}[temperature_at]" value="${temp}" data-temp-mirror="${rowId}">
            </td>
            <td>
                <input type="hidden" name="${minBase}[days]" value="0">
                <input type="hidden" name="${minBase}[hours]" value="0">
                <input type="hidden" name="${minBase}[minutes]" value="0">
                <input type="hidden" name="${minBase}[kind]" value="duration">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${minBase}"
                        data-target-label="Минимальный интервал перекрытия при +${temp}°C"
                        data-required="1"
                        data-allow-unlimited="0"
                        data-current-kind="duration">
                    <i class="bi bi-pencil"></i> не задано
                </button>
            </td>
            <td>
                <input type="hidden" name="${maxBase}[days]" value="0">
                <input type="hidden" name="${maxBase}[hours]" value="0">
                <input type="hidden" name="${maxBase}[minutes]" value="0">
                <input type="hidden" name="${maxBase}[kind]" value="unlimited">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${maxBase}"
                        data-target-label="Максимальный интервал перекрытия при +${temp}°C"
                        data-required="0"
                        data-allow-unlimited="1"
                        data-current-kind="unlimited">
                    <i class="bi bi-pencil"></i> без ограничения
                </button>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-action="click->coating-form#removeRow" title="Удалить точку">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;
    }

    /** Генерирует HTML панели для свежей env-ветки (одна точка по умолчанию). */
    _envPaneHTML(envKey) {
        const minPrefix = `minRecoatingInterval[branches][${envKey}][default][points]`;
        const maxPrefix = `maxRecoatingInterval[branches][${envKey}][default][points]`;
        const nodeId = envKey;
        const rowHTML = this._pairedRecoatingRow(minPrefix, maxPrefix, nodeId, 0);
        const baseLabels = this._baseLabelsFromRoot();
        const baseItems = Object.entries(baseLabels).map(([key, label]) => `
            <li><button type="button" class="dropdown-item"
                        data-action="click->coating-form#addBase"
                        data-coating-form-env-param="${envKey}"
                        data-coating-form-base-param="${key}">${label}</button></li>
        `).join('');
        const baseDropdown = `
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-success dropdown-toggle"
                        data-bs-toggle="dropdown" type="button">
                    + Правило для основы ЛКМ
                </button>
                <ul class="dropdown-menu">${baseItems}</ul>
            </div>`;
        return `
            <div data-recoating-node="${nodeId}">
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 table-rows">
                    <thead><tr>
                        <th class="text-muted fw-normal" style="width: 160px;">Температура</th>
                        <th class="text-muted fw-normal">Минимальный</th>
                        <th class="text-muted fw-normal">Максимальный</th>
                        <th style="width: 60px;"></th>
                    </tr></thead>
                    <tbody data-series="recoating-${nodeId}" data-min-prefix="${minPrefix}" data-max-prefix="${maxPrefix}">
                        <tr>${rowHTML}</tr>
                    </tbody>
                </table>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            data-action="click->coating-form#addRow"
                            data-coating-form-tbody-param="recoating-${nodeId}">+ Точка</button>
                    ${baseDropdown}
                </div>
            </div>`;
    }

    /** Карта {key_lower: "Русское название (ISO)"} оснований из data-атрибута root. */
    _baseLabelsFromRoot() {
        const root = this.element.querySelector('[data-recoating-root]');
        const raw = root?.dataset.availableBases;
        if (!raw) return {};
        try {
            const parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
        } catch {
            return {};
        }
    }
}
