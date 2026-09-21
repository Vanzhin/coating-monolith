import { Controller } from '@hotwired/stimulus';

/**
 * Фото отчёта: выбор/съёмка → онлайн-загрузка (stage) → в hidden падает uuid. Нет сети — плитка
 * «нет сети, догрузим», ретрай при появлении сети (в пределах сессии, без сохранения на диск).
 * Подпись — рядом. Имена content[photos][items][N][file|caption] держатся сплошными (переиндексация).
 */
export default class extends Controller {
    static targets = ['tiles', 'template', 'input'];
    static values = { stageUrl: { type: String, default: '' } };

    /** Даунскейл перед загрузкой: макс. сторона (px) и качество JPEG. */
    static MAX_SIDE = 2000;
    static JPEG_QUALITY = 0.85;

    connect() {
        this._pending = [];
        this._onOnline = this._retry.bind(this);
        window.addEventListener('online', this._onOnline);
        this._renumber();
    }

    disconnect() {
        window.removeEventListener('online', this._onOnline);
    }

    pick(event) {
        const files = Array.from(event.target.files || []);
        event.target.value = '';
        files.forEach(f => this._addTile(f));
    }

    async _addTile(file) {
        this.tilesTarget.appendChild(this.templateTarget.content.cloneNode(true));
        const tile = this.tilesTarget.lastElementChild;
        const preview = tile.querySelector('.report-photo-preview');
        const img = document.createElement('img');
        img.className = 'report-photo-img';
        img.src = URL.createObjectURL(file); // превью — из оригинала, мгновенно
        preview.innerHTML = '';
        preview.appendChild(img);
        this._renumber();

        // Уменьшаем ОДИН раз перед загрузкой; дальше _upload/_retry работают с уменьшенным.
        this._setState(tile, 'uploading');
        const prepared = await this._downscale(file);
        this._upload(prepared, tile);
    }

    /**
     * Уменьшение картинки в браузере до загрузки (меньше трафик/хранилище). EXIF-ориентация — через
     * createImageBitmap(imageOrientation:'from-image'), иначе фото ляжет боком. Уже мелкие не трогаем.
     * Любая осечка (HEIC на не-Safari, нет поддержки) → грузим оригинал.
     */
    async _downscale(file) {
        if (!/^image\//.test(file.type) || typeof createImageBitmap !== 'function') {
            return file;
        }
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const max = this.constructor.MAX_SIDE;
            const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
            if (scale >= 1) {
                bitmap.close?.();

                return file; // уже в пределах — не пересжимаем
            }
            const w = Math.round(bitmap.width * scale);
            const h = Math.round(bitmap.height * scale);
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);
            bitmap.close?.();
            const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', this.constructor.JPEG_QUALITY));
            if (!blob) {
                return file;
            }
            const name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';

            return new File([blob], name, { type: 'image/jpeg' });
        } catch (err) {
            return file;
        }
    }

    async _upload(file, tile) {
        this._setState(tile, 'uploading');
        const fd = new FormData();
        fd.append('files', file);
        try {
            const r = await fetch(this.stageUrlValue, { method: 'POST', credentials: 'same-origin', body: fd });
            if (r.ok) {
                const j = await r.json();
                const d = j.data ?? j;
                const uuid = d.files && d.files[0] ? d.files[0].uuid : '';
                if (uuid) {
                    tile.querySelector('[data-report-photos-target="file"]').value = uuid;
                    this._setState(tile, 'ok');
                    return;
                }
            }
            this._fail(tile, file);
        } catch (err) {
            this._fail(tile, file);
        }
    }

    _fail(tile, file) {
        this._setState(tile, 'pending');
        this._pending.push({ tile, file });
    }

    _retry() {
        const pending = this._pending;
        this._pending = [];
        pending.forEach(({ tile, file }) => {
            if (tile.isConnected) this._upload(file, tile);
        });
    }

    _setState(tile, state) {
        tile.dataset.state = state;
        const badge = tile.querySelector('.report-photo-badge');
        if (badge) {
            badge.textContent = state === 'pending' ? 'нет сети — догрузим' : (state === 'uploading' ? 'загрузка…' : '');
        }
    }

    remove(event) {
        const tile = event.target.closest('[data-report-photos-target="tile"]');
        if (tile) tile.remove();
        this._renumber();
    }

    _renumber() {
        const tiles = this.tilesTarget.querySelectorAll('[data-report-photos-target="tile"]');
        tiles.forEach((tile, index) => {
            tile.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/\[(?:\d+|__i__)\](\[[^\]]*\])$/, `[${index}]$1`);
            });
        });
    }
}
