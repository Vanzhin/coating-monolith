import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Управляет формой редактирования покрытия:
 *  - модалкой длительности (#durationModal) — открытие, заполнение, сохранение,
 *  - универсальным добавлением/удалением строк во всех температурно-зависимых
 *    подсекциях (data-series="dryToTouch|fullCure|minRecoatingInterval|…).
 *
 * Чтобы добавить новый температурно-зависимый параметр, достаточно повторить
 * в шаблоне таблицу с `<tbody data-series="..">` (и `data-series-required="0"`
 * если серия может быть пустой), и кнопку «Добавить точку» с тем же data-series.
 */
export default class extends Controller {
    static targets = [
        'modal', 'modalLabel',
        'modalDays', 'modalHours', 'modalMinutes',
        'calcBtn',
    ];

    connect() {
        this.currentName = null;
        if (this.hasModalTarget) {
            this._onShow = this._handleModalShow.bind(this);
            this.modalTarget.addEventListener('show.bs.modal', this._onShow);
        }
    }

    disconnect() {
        if (this.hasModalTarget && this._onShow) {
            this.modalTarget.removeEventListener('show.bs.modal', this._onShow);
        }
    }

    // ─── Модалка ───────────────────────────────────────────────

    _handleModalShow(event) {
        const btn = event.relatedTarget;
        this.currentName = btn.getAttribute('data-target-name');

        // Заголовок: подставляем актуальную температуру строки.
        const tr = btn.closest('tr');
        const tempInput = tr?.querySelector('input[name$="[temperature_at]"]');
        const labelTpl = btn.getAttribute('data-target-label') || 'Длительность';
        this.modalLabelTarget.textContent = tempInput
            ? labelTpl.replace(/\+-?\d+\s*°C/, '+' + tempInput.value + ' °C')
            : labelTpl;

        this.modalDaysTarget.value = this._hidden(this.currentName, 'days')?.value || 0;
        this.modalHoursTarget.value = this._hidden(this.currentName, 'hours')?.value || 0;
        this.modalMinutesTarget.value = this._hidden(this.currentName, 'minutes')?.value || 0;

        // «Рассчитать» доступна только если в соседних строках есть минимум две заполненные точки.
        if (this.hasCalcBtnTarget) {
            this.calcBtnTarget.disabled = tr
                ? this._gatherSiblingPoints(tr, this._seriesPrefixOf(this.currentName)).length < 2
                : true;
        }
    }

    /** «minRecoatingInterval[2]» → «minRecoatingInterval». */
    _seriesPrefixOf(name) {
        const match = name?.match(/^([a-zA-Z]+)\[\d+\]/);
        return match ? match[1] : null;
    }

    /** Синхронизирует температуру в hidden-mirror строки (для парных серий вроде recoatingInterval). */
    syncTemperature(event) {
        const input = event.currentTarget;
        const i = input.dataset.row;
        const mirror = this.element.querySelector(`input[data-temp-mirror="${i}"]`);
        if (mirror) mirror.value = input.value;
    }

    saveDuration() {
        if (!this.currentName) return;
        this._hidden(this.currentName, 'days').value = this._intVal(this.modalDaysTarget);
        this._hidden(this.currentName, 'hours').value = this._intVal(this.modalHoursTarget);
        this._hidden(this.currentName, 'minutes').value = this._intVal(this.modalMinutesTarget);

        const btn = this.element.querySelector(`button[data-target-name="${this.currentName}"]`);
        if (btn) this._refreshButton(btn);

        Modal.getOrCreateInstance(this.modalTarget).hide();
    }

    clearDuration() {
        this.modalDaysTarget.value = 0;
        this.modalHoursTarget.value = 0;
        this.modalMinutesTarget.value = 0;
    }

    /** «Рассчитать» — линейная интерполяция через бэк (/cabinet/coating/series/interpolate). */
    async calculateDuration() {
        if (!this.currentName) return;
        const triggerBtn = this.element.querySelector(`button[data-target-name="${this.currentName}"]`);
        const tr = triggerBtn?.closest('tr');
        if (!tr) return;

        const targetTemp = parseInt(tr.querySelector('input[name$="[temperature_at]"]')?.value || 0, 10);
        const points = this._gatherSiblingPoints(tr, this._seriesPrefixOf(this.currentName));
        if (points.length < 2) return; // подстраховка — кнопка и так должна быть disabled

        this.calcBtnTarget.disabled = true;
        try {
            const response = await fetch('/cabinet/coating/series/interpolate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ targetTemperature: targetTemp, points }),
                credentials: 'same-origin',
            });
            // Глобальный ResponseListener оборачивает JSON в { result, status, data, message }.
            const envelope = await response.json();
            if (envelope.result !== 'success' || !envelope.data) {
                alert(envelope.message || 'Не удалось рассчитать значение.');
                return;
            }
            const decomposed = this._decompose(envelope.data.minutes);
            this.modalDaysTarget.value = decomposed.days;
            this.modalHoursTarget.value = decomposed.hours;
            this.modalMinutesTarget.value = decomposed.minutes;
        } catch (e) {
            alert('Ошибка обращения к серверу: ' + e.message);
        } finally {
            this.calcBtnTarget.disabled = false;
        }
    }

    // ─── Добавление / удаление строк ────────────────────────────

    addRow(event) {
        const series = event.currentTarget.dataset.series;
        if (!series) return;
        const tbody = this.element.querySelector(`tbody[data-series="${series}"]`);
        if (!tbody) return;
        const i = tbody.children.length;
        const tr = document.createElement('tr');
        tr.innerHTML = this._rowHTML(series, i);
        tbody.appendChild(tr);
    }

    removeRow(event) {
        const tr = event.currentTarget.closest('tr');
        const tbody = tr?.parentElement;
        if (!tbody) return;
        // Если серия обязательна (по умолчанию), нельзя удалить последнюю точку.
        const required = tbody.dataset.seriesRequired !== '0';
        if (required && tbody.children.length <= 1) {
            alert('Нужна хотя бы одна точка.');
            return;
        }
        tr.remove();
    }

    // ─── Утилиты ───────────────────────────────────────────────

    _hidden(name, suffix) {
        return this.element.querySelector(`input[type=hidden][name="${name}[${suffix}]"]`);
    }

    _intVal(input) {
        return parseInt(input.value || 0, 10);
    }

    /**
     * Собирает заполненные точки из соседних строк той же tbody — конкретно для серии
     * с префиксом `seriesPrefix` (например, "minRecoatingInterval"). Пустые (все 0) пропускаются.
     */
    _gatherSiblingPoints(currentTr, seriesPrefix) {
        const tbody = currentTr.parentElement;
        if (!tbody || !seriesPrefix) return [];
        const points = [];
        for (const otherTr of tbody.querySelectorAll('tr')) {
            if (otherTr === currentTr) continue;
            const tempInput = otherTr.querySelector(`input[name^="${seriesPrefix}["][name$="[temperature_at]"]`);
            if (!tempInput) continue;
            const probe = otherTr.querySelector(`input[type=hidden][name^="${seriesPrefix}["][name$="[days]"]`);
            if (!probe) continue;
            const base = probe.name.replace(/\[days\]$/, '');
            const d = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[days]"]`)?.value || 0, 10);
            const h = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[hours]"]`)?.value || 0, 10);
            const m = parseInt(otherTr.querySelector(`input[type=hidden][name="${base}[minutes]"]`)?.value || 0, 10);
            const total = d * 1440 + h * 60 + m;
            if (total === 0) continue;
            points.push({ temperature_at: parseInt(tempInput.value || 0, 10), minutes: total });
        }
        return points;
    }

    _refreshButton(btn) {
        const name = btn.getAttribute('data-target-name');
        const required = btn.getAttribute('data-required') === '1';
        const d = parseInt(this._hidden(name, 'days')?.value || 0, 10);
        const h = parseInt(this._hidden(name, 'hours')?.value || 0, 10);
        const m = parseInt(this._hidden(name, 'minutes')?.value || 0, 10);
        const total = d * 1440 + h * 60 + m;

        btn.classList.remove('btn-outline-secondary', 'text-muted', 'btn-outline-primary');
        if (total === 0) {
            btn.classList.add('btn-outline-secondary', 'text-muted');
            btn.innerHTML = '<i class="bi bi-pencil"></i> ' + (required ? 'не задано' : 'без ограничения');
        } else {
            btn.classList.add('btn-outline-primary');
            btn.innerHTML = this._format(total);
        }
    }

    _format(totalMinutes) {
        const days = Math.floor(totalMinutes / 1440);
        let rem = totalMinutes - days * 1440;
        const hours = Math.floor(rem / 60);
        const minutes = rem - hours * 60;
        const parts = [];
        if (days > 0) parts.push(days + ' д');
        if (hours > 0) parts.push(hours + ' ч');
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

    /**
     * Шаблон строки.
     * - Для одиночных серий (dryToTouch / fullCure / min или maxRecoatingInterval по отдельности):
     *   «температура → одна длительность».
     * - Для виртуальной серии recoatingInterval (одна tbody, две параллельные серии под капотом):
     *   «температура → Min длительность → Max длительность» с зеркалом температуры в maxRecoatingInterval.
     */
    _rowHTML(series, i) {
        if (series === 'recoatingInterval') {
            return this._pairedRecoatingRow(i);
        }
        return this._singleSeriesRow(series, i);
    }

    _singleSeriesRow(series, i) {
        const labels = {
            dryToTouch:           'Сухой на отлип',
            fullCure:             'Полное отверждение',
            minRecoatingInterval: 'Минимальный интервал перекрытия',
            maxRecoatingInterval: 'Максимальный интервал перекрытия',
        };
        const label = labels[series] || series;
        const base = `${series}[${i}]`;
        return `
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="${base}[temperature_at]" value="20"
                           class="form-control" min="-50" max="100" required>
                    <span class="input-group-text">°C</span>
                </div>
            </td>
            <td>
                <input type="hidden" name="${base}[days]" value="0">
                <input type="hidden" name="${base}[hours]" value="0">
                <input type="hidden" name="${base}[minutes]" value="0">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${base}"
                        data-target-label="${label} при +20°C"
                        data-required="1">
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

    _pairedRecoatingRow(i) {
        const minBase = `minRecoatingInterval[${i}]`;
        const maxBase = `maxRecoatingInterval[${i}]`;
        return `
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="${minBase}[temperature_at]" value="20"
                           class="form-control" data-row="${i}"
                           data-action="input->coating-form#syncTemperature"
                           min="-50" max="100" required>
                    <span class="input-group-text">°C</span>
                </div>
                <input type="hidden" name="${maxBase}[temperature_at]" value="20" data-temp-mirror="${i}">
            </td>
            <td>
                <input type="hidden" name="${minBase}[days]" value="0">
                <input type="hidden" name="${minBase}[hours]" value="0">
                <input type="hidden" name="${minBase}[minutes]" value="0">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${minBase}"
                        data-target-label="Минимальный интервал перекрытия при +20°C"
                        data-required="1">
                    <i class="bi bi-pencil"></i> не задано
                </button>
            </td>
            <td>
                <input type="hidden" name="${maxBase}[days]" value="0">
                <input type="hidden" name="${maxBase}[hours]" value="0">
                <input type="hidden" name="${maxBase}[minutes]" value="0">
                <button type="button" class="btn btn-sm duration-display-btn btn-outline-secondary text-muted"
                        data-bs-toggle="modal" data-bs-target="#durationModal"
                        data-target-name="${maxBase}"
                        data-target-label="Максимальный интервал перекрытия при +20°C"
                        data-required="0">
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
}
