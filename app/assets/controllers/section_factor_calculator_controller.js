import { Controller } from '@hotwired/stimulus';

// Формулы — зеркало доменных SectionFactor\* на бэке (источник истины). Дублируем ради офлайна.
// ПТМ = площадь сечения / обогреваемый периметр (НПБ 236-97), периметр — развёрнутый контур.
const STEEL_DENSITY = 7.85;
const rad2deg = (r) => (r * 180) / Math.PI;
const deg2rad = (d) => (d * Math.PI) / 180;

// Геометрия по типу профиля: dims (объект размеров) → площадь и периметр по схеме обогрева.
const GEO = {
    i_beam: {
        area: (d) => (d.height - 2 * d.flangeThickness) * d.webThickness
            + 2 * d.flangeWidth * d.flangeThickness
            + 4 * (d.filletRadius || 0) ** 2 * (1 - Math.PI / 4),
        perimeter: (d, s) => {
            const side = d.height + d.flangeWidth - d.webThickness;
            return (s.top ? d.flangeWidth : 0) + (s.bottom ? d.flangeWidth : 0) + (s.left ? side : 0) + (s.right ? side : 0);
        },
    },
    channel: {
        area: (d) => {
            const a = Math.atan(d.slope || 0);
            const corr = (x) => x * x / Math.tan(deg2rad((90 + rad2deg(a)) / 2)) - x * x * Math.PI * (90 - rad2deg(a)) / 360;
            return d.height * d.webThickness + 2 * (d.flangeWidth - d.webThickness) * d.flangeThickness
                + 2 * corr(d.innerRadius || 0) - 2 * corr(d.outerRadius || 0);
        },
        perimeter: (d, s) => {
            const a = Math.atan(d.slope || 0);
            const halfDeg = 90 - (90 + rad2deg(a)) / 2;
            const corr = (x) => 2 * x * Math.PI * 2 * halfDeg / 360 - 2 * x * Math.tan(deg2rad(halfDeg));
            let p = (s.top ? d.flangeWidth : 0) + (s.bottom ? d.flangeWidth : 0) + (s.left ? d.height : 0);
            if (s.right) {
                p += d.height + 2 * (d.flangeWidth - d.webThickness) * (1 / Math.cos(a) - (d.slope || 0))
                    + 2 * corr(d.innerRadius || 0) + 2 * corr(d.outerRadius || 0);
            }
            return p;
        },
    },
    angle: {
        area: (d) => (d.legA + d.legB - d.thickness) * d.thickness
            + (d.outerRadius || 0) ** 2 * (1 - Math.PI / 4) - 2 * (d.innerRadius || 0) ** 2 * (1 - Math.PI / 4),
        perimeter: (d, s) => {
            const c = Math.PI / 2 - 2;
            return (d.legA + d.legB) + (d.outerRadius || 0) * c + 2 * (d.innerRadius || 0) * c
                + (s.top ? d.legB : 0) + (s.left ? d.legA : 0);
        },
    },
    pipe: {
        area: (d) => Math.PI / 4 * (d.outerDiameter ** 2 - (d.outerDiameter - 2 * d.wallThickness) ** 2),
        perimeter: (d) => Math.PI * d.outerDiameter,
    },
    round_bar: {
        area: (d) => Math.PI * d.diameter ** 2 / 4,
        perimeter: (d) => Math.PI * d.diameter,
    },
    rect_hollow: {
        area: (d) => {
            const base = 2 * d.wallThickness * (d.height + d.width - 2 * d.wallThickness);
            if (!d.outerRadius) return base;
            const ri = Math.max(d.outerRadius - d.wallThickness, 0);
            return base - (4 - Math.PI) * (d.outerRadius ** 2 - ri ** 2);
        },
        perimeter: (d, s) => (s.top ? d.width : 0) + (s.bottom ? d.width : 0) + (s.left ? d.height : 0) + (s.right ? d.height : 0),
    },
    sheet: {
        area: (d) => d.thickness * 1000,
        perimeter: (d, s) => ((s.top ? 1 : 0) + (s.bottom ? 1 : 0)) * 1000,
    },
};

// Какие грани показывать в схеме обогрева и есть ли пресеты 4/3-стороннее.
const FACE_UI = {
    i_beam: { faces: ['top', 'bottom', 'left', 'right'], presets: true },
    channel: { faces: ['top', 'bottom', 'left', 'right'], presets: true },
    rect_hollow: { faces: ['top', 'bottom', 'left', 'right'], presets: true },
    angle: { faces: ['top', 'left'], presets: false, labels: { top: 'Полка 1', left: 'Полка 2' } },
    sheet: { faces: ['top', 'bottom'], presets: false, labels: { top: 'Сверху', bottom: 'Снизу' } },
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
            if (shown && ui.labels && ui.labels[face]) {
                chk.querySelector('.sf-facechk-lbl').textContent = ui.labels[face];
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
        this.mode = event.currentTarget.dataset.sfMode;
        this.element.querySelectorAll('[data-sf-mode]').forEach((b) => b.setAttribute('aria-pressed', b === event.currentTarget));
        this.element.querySelector('.sf-sortament').hidden = this.mode !== 'sortament';
        this.element.querySelector('.sf-manual').hidden = this.mode !== 'dims';
        if ('sortament' === this.mode) this.loadSortament();
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
        if (!this.sortament || !this.sortament.types[this.type]) {
            std.innerHTML = '<option>загрузка…</option>';
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
        sel.closest('.sf-step').classList.remove('is-locked');
        // сбросить и заблокировать глубже
        for (let i = index + 1; i < this.levels.length; i++) {
            const deeper = this.element.querySelector(`[data-sf-role="level"][data-level="${i}"]`);
            deeper.innerHTML = '<option>—</option>';
            deeper.closest('.sf-step').classList.add('is-locked');
        }
        this.descend();
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
            const n = ['top', 'bottom', 'left', 'right'].filter((f) => this.scheme[f]).length;
            cap.textContent = FACE_UI[this.type].faces.length === 0 ? 'по контуру' : (n === 4 ? '4 стороны' : n === 3 ? '3 стороны' : n + ' стор.');
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
        const dims = this.currentDims();
        const out = (k, v) => { const el = this.element.querySelector(`[data-sf-out="${k}"]`); if (el) el.textContent = v; };
        const dash = () => ['ptm', 'perimeter', 'spm', 'spt', 'mass'].forEach((k) => out(k, '—'));

        if (!dims) return dash();
        try {
            const geo = GEO[this.type];
            const area = geo.area(dims);
            const perimeter = geo.perimeter(dims, this.scheme);
            if (!(area > 0) || !(perimeter > 0)) return dash();

            const massPerMeter = area / 1000 * STEEL_DENSITY;
            out('ptm', this.fmt(area / perimeter, 2));
            out('perimeter', this.fmt(perimeter, 0));
            out('spm', this.fmt(perimeter / 1000, 3));
            out('mass', this.fmt(massPerMeter, 1));
            out('spt', this.fmt(1000 / massPerMeter * (perimeter / 1000), 1));
        } catch (e) {
            dash();
        }
    }

    fmt(x, d) {
        return x.toLocaleString('ru-RU', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
}
