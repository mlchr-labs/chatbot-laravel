const ALLOWED_EXTENSIONS = ['pdf', 'txt', 'md', 'html'];
const MAX_BYTES = 16 * 1024 * 1024;
const POLL_INTERVAL_MS = 3000;

const BADGE_CLASSES = {
    pending: 'bg-gray-100 text-gray-700',
    extracting: 'bg-yellow-100 text-yellow-800',
    embedding: 'bg-yellow-100 text-yellow-800',
    indexed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};

// Client side checks are only UX: the server validates type, size and real content again.
export default ({ sources, indexUrl, storeUrl, csrf }) => ({
    sources,
    uploads: [],
    dragging: false,
    toDelete: null,
    retryError: null,
    pollTimer: null,
    nextUploadId: 1,

    init() {
        this.schedulePoll();
    },

    destroy() {
        clearTimeout(this.pollTimer);
    },

    badgeClass(status) {
        return BADGE_CLASSES[status] ?? BADGE_CLASSES.pending;
    },

    formatSize(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    },

    formatDate(iso) {
        return iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '-';
    },

    onDrop(event) {
        this.dragging = false;
        this.addFiles(event.dataTransfer?.files ?? []);
    },

    onPick(event) {
        this.addFiles(event.target.files);
        event.target.value = '';
    },

    addFiles(fileList) {
        Array.from(fileList).forEach((file) => {
            const upload = { id: this.nextUploadId++, name: file.name, progress: 0, error: null };
            const extension = file.name.split('.').pop().toLowerCase();

            if (!ALLOWED_EXTENSIONS.includes(extension)) {
                upload.error = 'Tipo não suportado. Use PDF, TXT, MD ou HTML.';
            } else if (file.size > MAX_BYTES) {
                upload.error = 'O arquivo passa de 16 MB.';
            }

            this.uploads.push(upload);

            if (!upload.error) {
                this.send(file, upload);
            }
        });
    },

    // XMLHttpRequest instead of fetch: fetch cannot report upload progress.
    send(file, upload) {
        const body = new FormData();
        body.append('file', file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', storeUrl);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                upload.progress = Math.round((event.loaded / event.total) * 100);
            }
        };

        xhr.onload = () => {
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch (e) {
                // Non JSON body (proxy error page, for instance): handled below.
            }

            if (xhr.status === 201 && payload?.data) {
                this.sources.unshift(payload.data);
                this.uploads = this.uploads.filter((item) => item.id !== upload.id);
                this.schedulePoll();
                return;
            }

            upload.error = payload?.errors?.file?.[0]
                ?? (xhr.status === 413 ? 'O arquivo passa de 16 MB.' : 'Não foi possível enviar o arquivo.');
        };

        xhr.onerror = () => {
            upload.error = 'Falha de rede durante o envio.';
        };

        xhr.send(body);
    },

    dismiss(upload) {
        this.uploads = this.uploads.filter((item) => item.id !== upload.id);
    },

    askDelete(source) {
        this.toDelete = source;
        this.$dispatch('open-modal', 'delete-source');
    },

    async confirmDelete() {
        const source = this.toDelete;
        if (!source) return;

        // Optimistic: the server hides it right away too.
        this.sources = this.sources.filter((item) => item.ulid !== source.ulid);
        this.toDelete = null;

        const response = await fetch(`${storeUrl}/${source.ulid}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            await this.refresh();
        }
    },

    async retry(source) {
        this.retryError = null;

        // Optimistic: the row goes back to the queue while the server clears the old index.
        const previous = { ...source };
        Object.assign(source, { status: 'pending', status_label: 'Na fila', processing: true, error: null, can_retry: false });
        this.schedulePoll();

        try {
            const response = await fetch(`${storeUrl}/${source.ulid}/retry`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (response.ok) return;

            Object.assign(source, previous);
            this.retryError = response.status === 429
                ? 'Muitas tentativas seguidas. Aguarde um minuto e tente de novo.'
                : ((await response.json().catch(() => null))?.message ?? 'Não foi possível reiniciar o processamento.');
        } catch (e) {
            Object.assign(source, previous);
            this.retryError = 'Falha de rede. Tente novamente.';
        }

        await this.refresh();
    },

    hasProcessing() {
        return this.sources.some((source) => source.processing);
    },

    // Polls only while something is still being processed.
    schedulePoll() {
        clearTimeout(this.pollTimer);

        if (!this.hasProcessing()) return;

        this.pollTimer = setTimeout(async () => {
            await this.refresh();
            this.schedulePoll();
        }, POLL_INTERVAL_MS);
    },

    async refresh() {
        try {
            const response = await fetch(indexUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (response.ok) {
                this.sources = (await response.json()).data;
            }
        } catch (e) {
            // Transient network error: the next poll tries again.
        }
    },
});
