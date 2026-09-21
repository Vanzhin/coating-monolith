import { Controller } from '@hotwired/stimulus';

/**
 * Фото отчёта: выбор/съёмка → онлайн-загрузка (stage) → в hidden падает uuid. Нет сети — плитка
 * «нет сети, догрузим», ретрай при появлении сети (в пределах сессии, без сохранения на диск).
 * Подпись — рядом. Имена content[photos][items][N][file|caption] держатся сплошными (переиндексация).
 */
export default class extends Controller {
    static targets = ['tiles', 'template', 'input'];
    static values = { stageUrl: { type: String, default: '' } };

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

    _addTile(file) {
        this.tilesTarget.appendChild(this.templateTarget.content.cloneNode(true));
        const tile = this.tilesTarget.lastElementChild;
        const preview = tile.querySelector('.report-photo-preview');
        const img = document.createElement('img');
        img.className = 'report-photo-img';
        img.src = URL.createObjectURL(file);
        preview.innerHTML = '';
        preview.appendChild(img);
        this._renumber();
        this._upload(file, tile);
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
