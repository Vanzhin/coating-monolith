import { Controller } from '@hotwired/stimulus';
import { compute } from '../section_factor_geometry.mjs';

// Калькулятор приведённой толщины металла. Формулы (площадь/периметр/ПТМ) вынесены в чистый модуль
// section_factor_geometry.mjs — единое зеркало домена, прибитое тестом паритета PHP↔JS. Здесь — только UI:
// выбор типа/сортамента, схема обогрева, подсветка граней, вывод результатов.

// Подписи граней по умолчанию (коробчатые профили). Кастомные подписи типа перекрывают их,
// но при смене типа подпись ВСЕГДА возвращается сюда — иначе «Наружная полка» уголка залипает
// на швеллере/двутавре.
const DEFAULT_FACE_LABELS = { top: 'Сверху', bottom: 'Снизу', left: 'Слева', right: 'Справа' };

// Какие грани показывать в схеме обогрева и есть ли пресеты 4/3-стороннее.
// Уголок — не коробка: три независимые грани (две наружные полки + внутренний контур); прижата
// полка к стене → снять её грань, уголок зашит в сечение → снять внутренний контур. Пресетов нет.
const FACE_UI = {
    i_beam: { faces: ['top', 'bottom', 'left', 'right'], presets: true },
    channel: { faces: ['top', 'bottom', 'left', 'right'], presets: true },
    rect_hollow: { faces: ['top', 'bottom', 'left', 'right'], presets: true },
    angle: {
        faces: ['top', 'left', 'right'], presets: false,
        labels: { top: 'Наружная полка b₂', left: 'Наружная полка b₁', right: 'Внутренний контур' },
    },
    sheet: { faces: ['top', 'bottom'], presets: false },
    pipe: { faces: [], presets: false, note: 'Обогрев по всему контуру' },
    round_bar: { faces: [], presets: false, note: 'Обогрев по всему контуру' },
};

const SORTAMENT_URL = '/api/tools/section-factor/sortament';
const CACHE_KEY = 'sf_sortament';

export default class extends Controller {
    connect() {
        this.mode = 'sortament';
        this.scheme = { top: true, bottom: true, left: true, right: true };
        this.sortament = null;
        this.leaf = null;
        this.type = this.element.querySelector('.sf-type.is-active')?.dataset.type || 'i_beam';
        this.applyType();
        this.loadSortament();
    }

    // --- выбор типа профиля ---
    selectType(event) {
        this.type = event.currentTarget.dataset.type;
        this.element.querySelectorAll('.sf-type').forEach((t) => t.classList.toggle('is-active', t.dataset.type === this.type));
        this.leaf = null;
        this.applyType();
    }

    applyType() {
        // схема (SVG) и набор ручных размеров — по типу
        this.element.querySelectorAll('.sf-stage').forEach((s) => (s.hidden = s.dataset.type !== this.type));
        this.element.querySelectorAll('.sf-dims').forEach((d) => (d.hidden = d.dataset.type !== this.type));

        // грани обогрева под тип
        const ui = FACE_UI[this.type];
        this.element.querySelectorAll('.sf-facechk').forEach((chk) => {
            const face = chk.dataset.face;
            const shown = ui.faces.includes(face);
            chk.hidden = !shown;
            if (shown) {
                chk.querySelector('.sf-facechk-lbl').textContent = (ui.labels && ui.labels[face]) || DEFAULT_FACE_LABELS[face];
            }
        });
        const facesBox = this.element.querySelector('.sf-faces');
        const presets = this.element.querySelector('.sf-presets');
        const note = this.element.querySelector('.sf-heat-note');
        if (facesBox) facesBox.hidden = ui.faces.length === 0;
        if (presets) presets.hidden = !ui.presets;
        if (note) { note.hidden = !ui.note; note.textContent = ui.note || ''; }

        // грани по умолчанию: все показанные включены (труба/круг — контур)
        this.scheme = { top: false, bottom: false, left: false, right: false };
        (ui.faces.length ? ui.faces : ['top', 'bottom', 'left', 'right']).forEach((f) => (this.scheme[f] = true));

        this.rebuildCascade();
        this.syncFaces();
        this.recompute();
    }

    // --- режим ввода ---
    setMode(event) {
        this.applyMode(event.currentTarget.dataset.sfMode);
    }

    // Переключение режима (из клика и программно — для типов без сортамента).
    applyMode(mode) {
        this.mode = mode;
        this.element.querySelectorAll('[data-sf-mode]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.sfMode === mode));
        this.element.querySelector('.sf-sortament').hidden = mode !== 'sortament';
        this.element.querySelector('.sf-manual').hidden = mode !== 'dims';
        if ('sortament' === mode) this.loadSortament();
        this.recompute();
    }

    // --- сортамент (офлайн-кэш) ---
    async loadSortament() {
        if (!this.sortament) {
            try {
                const res = await fetch(SORTAMENT_URL);
                if (res.ok) {
                    const body = await res.json();
                    this.sortament = body.data || body; // общий конверт {result,status,data}
                    try { localStorage.setItem(CACHE_KEY, JSON.stringify(this.sortament)); } catch (e) { /* quota */ }
                }
            } catch (e) { /* офлайн — читаем кэш */ }
            if (!this.sortament) {
                const cached = localStorage.getItem(CACHE_KEY);
                if (cached) this.sortament = JSON.parse(cached);
            }
        }
        this.rebuildCascade();
    }

    rebuildCascade() {
        const std = this.element.querySelector('[data-sf-role="standard"]');
        if (!std) return;
        const hasSortament = !!(this.sortament && this.sortament.types[this.type]);
        // Тумблер «Из сортамента / По размерам» нужен только типам с сортаментом. У трубы/круга/листа
        // его нет — там только ручной ввод, тумблер прячем (иначе сортамент «висит» на «загрузка…»).
        const modeSeg = this.element.querySelector('.sf-seg[aria-label="Способ ввода"]');
        if (modeSeg) modeSeg.hidden = !!this.sortament && !hasSortament;
        if (!hasSortament) {
            // Сортамент ещё грузится — «загрузка…»; загружен, но у типа его нет — показываем размеры.
            if (this.sortament) this.applyMode('dims');
            else std.innerHTML = '<option>загрузка…</option>';
            return;
        }
        const standards = this.sortament.types[this.type].standards;
        std.innerHTML = Object.entries(standards)
            .map(([slug, node]) => `<option value="${slug}">${node.label}</option>`).join('');
        this.onStandardChange();
    }

    onStandardChange() {
        const slug = this.element.querySelector('[data-sf-role="standard"]').value;
        const node = this.sortament.types[this.type].standards[slug];
        this.levels = node.levels;
        this.tree = node.tree;
        this.leaf = null;
        this.renderLevel(0, this.tree);
        // спрятать лишние уровни
        this.element.querySelectorAll('[data-sf-role="level"]').forEach((sel) => {
            const i = Number(sel.dataset.level);
            sel.closest('.sf-step').hidden = i >= this.levels.length;
            sel.closest('.sf-step').querySelector('.sf-step-name').textContent = this.levels[i] || '';
        });
        this.updateChosen();
        this.recompute();
    }

    renderLevel(index, node) {
        const sel = this.element.querySelector(`[data-sf-role="level"][data-level="${index}"]`);
        if (!sel) return;
        sel.innerHTML = Object.keys(node).map((k) => `<option value="${k}">${k}</option>`).join('');
        // Сразу раскрываем следующий уровень по первому варианту — водопад НЕ ждёт change. При выборе
        // стандарта/типа верхние уровни авто-выставлены в первый вариант без события, а повторный тап по
        // уже выбранному варианту change не шлёт. Без авто-спуска глубокие уровни застревали на «—» и лист
        // не находился (баг «Номер = —» на 2–3-уровневых стандартах). На последнем уровне — спуск к листу.
        if (index + 1 < this.levels.length) {
            this.renderLevel(index + 1, node[sel.value]);
        } else {
            this.descend();
        }
    }

    onLevelChange(event) {
        const index = Number(event.currentTarget.dataset.level);
        const node = this.nodeAt(index);
        if (index + 1 < this.levels.length) {
            this.renderLevel(index + 1, node[event.currentTarget.value]);
        } else {
            this.descend();
        }
    }

    // спуститься по текущему выбору до листа, если дошли
    descend() {
        this.leaf = null;
        if (this.levels && this.levels.length) {
            const leafNode = this.nodeAt(this.levels.length - 1);
            const lastSel = this.element.querySelector(`[data-sf-role="level"][data-level="${this.levels.length - 1}"]`);
            const candidate = leafNode ? leafNode[lastSel.value] : null;
            if (candidate && (candidate.height !== undefined || candidate.legA !== undefined || candidate.outerDiameter !== undefined || candidate.diameter !== undefined || candidate.thickness !== undefined)) {
                this.leaf = candidate;
            }
        }
        this.updateChosen();
        this.recompute();
    }

    // узел дерева по выбору уровней [0..index-1]
    nodeAt(index) {
        let node = this.tree;
        for (let i = 0; i < index; i++) {
            const sel = this.element.querySelector(`[data-sf-role="level"][data-level="${i}"]`);
            node = node?.[sel.value];
        }
        return node;
    }

    updateChosen() {
        const chip = this.element.querySelector('.sf-chosen');
        if (!chip) return;
        if (this.leaf && this.levels) {
            const path = this.levels.map((_, i) => this.element.querySelector(`[data-sf-role="level"][data-level="${i}"]`).value);
            chip.hidden = false;
            chip.textContent = '✓ ' + path.join(' · ');
        } else {
            chip.hidden = true;
        }
    }

    // --- схема обогрева ---
    preset(event) {
        const four = event.currentTarget.dataset.sfPreset === '4';
        this.scheme = { top: four, bottom: true, left: true, right: true };
        this.element.querySelectorAll('[data-sf-preset]').forEach((b) => b.setAttribute('aria-pressed', b === event.currentTarget));
        this.syncFaces();
        this.recompute();
    }

    toggleFace(event) {
        this.scheme[event.currentTarget.dataset.face] = event.currentTarget.checked;
        this.syncFaces();
        this.recompute();
    }

    // Клик по стороне прямо на схеме — то же, что галочка (дублируется с кнопками).
    faceClick(event) {
        const face = event.currentTarget.dataset.face;
        this.scheme[face] = !this.scheme[face];
        this.syncFaces();
        this.recompute();
    }

    syncFaces() {
        this.element.querySelectorAll('.sf-facechk').forEach((chk) => {
            const on = !!this.scheme[chk.dataset.face];
            chk.querySelector('input').checked = on;
            chk.classList.toggle('is-hot', on && !chk.hidden);
        });
        const stage = this.element.querySelector(`.sf-stage[data-type="${this.type}"]`);
        if (stage) stage.querySelectorAll('.sf-face').forEach((f) => f.classList.toggle('is-hot', !!this.scheme[f.dataset.face]));
        const cap = stage?.querySelector('.sf-cap-val');
        if (cap) {
            if (this.type === 'angle') {
                // три независимые грани: две наружные полки + внутренний контур
                const total = (this.scheme.top ? 1 : 0) + (this.scheme.left ? 1 : 0) + (this.scheme.right ? 1 : 0);
                cap.textContent = total === 3 ? 'контур' : total + (total === 1 ? ' сторона' : ' стороны');
            } else if (FACE_UI[this.type].faces.length === 0) {
                cap.textContent = 'по контуру';
            } else {
                const n = ['top', 'bottom', 'left', 'right'].filter((f) => this.scheme[f]).length;
                cap.textContent = n === 4 ? '4 стороны' : n === 3 ? '3 стороны' : n + ' стор.';
            }
        }
    }

    // --- размеры текущего ввода ---
    currentDims() {
        if ('sortament' === this.mode) return this.leaf;
        const box = this.element.querySelector(`.sf-dims[data-type="${this.type}"]`);
        const dims = {};
        let ok = true;
        box.querySelectorAll('input[data-dim]').forEach((inp) => {
            const v = parseFloat(String(inp.value).replace(',', '.'));
            if (!Number.isFinite(v) || v <= 0) ok = false;
            dims[inp.dataset.dim] = v;
        });
        return ok ? dims : null;
    }

    onDimInput(event) {
        const el = event.target;
        el.value = el.value.replace(/,/g, '.').replace(/[^\d.]/g, '');
        this.recompute();
    }

    // --- расчёт ---
    recompute() {
        const out = (k, v) => { const el = this.element.querySelector(`[data-sf-out="${k}"]`); if (el) el.textContent = v; };
        const dash = () => ['ptm', 'perimeter', 'spm', 'spt', 'mass'].forEach((k) => out(k, '—'));
        try {
            const r = compute(this.type, this.currentDims(), this.scheme);
            if (!r) return dash();
            // всё до 3 знаков после запятой (как эталон)
            out('ptm', this.fmt(r.ptm, 3));
            out('perimeter', this.fmt(r.perimeter, 3));
            out('spm', this.fmt(r.spm, 3));
            out('mass', this.fmt(r.mass, 3));
            out('spt', this.fmt(r.spt, 3));
        } catch (e) {
            dash();
        }
    }

    fmt(x, d) {
        return x.toLocaleString('ru-RU', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
}
