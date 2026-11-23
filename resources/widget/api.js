export class ApiError extends Error {
    constructor(status, message, retryAfter = null) {
        super(message);
        this.status = status;
        this.retryAfter = retryAfter;
    }
}

async function request(url, { method = 'GET', token = null, body = null } = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== null) headers['Content-Type'] = 'application/json';
    if (token) headers.Authorization = `Bearer ${token}`;

    let res;
    try {
        res = await fetch(url, {
            method,
            headers,
            body: body !== null ? JSON.stringify(body) : undefined,
            credentials: 'omit',
            mode: 'cors',
        });
    } catch {
        throw new ApiError(0, 'Sem conexão. Tente novamente.');
    }

    let data = null;
    try {
        data = await res.json();
    } catch {
        /* corpo vazio ou nao JSON */
    }

    if (!res.ok) {
        const message =
            (data && typeof data.message === 'string' && data.message) ||
            (res.status === 429 ? 'Muitas mensagens. Aguarde um instante.' : 'Algo deu errado. Tente novamente.');
        throw new ApiError(res.status, message, res.headers.get('Retry-After'));
    }
    return data;
}

export function createApi(base) {
    return {
        config: (publicKey) => request(`${base}/api/widget/config/${encodeURIComponent(publicKey)}`),
        session: (payload) => request(`${base}/api/widget/sessions`, { method: 'POST', body: payload }),
        send: (ulid, token, content) =>
            request(`${base}/api/widget/conversations/${encodeURIComponent(ulid)}/messages`, {
                method: 'POST',
                token,
                body: { content },
            }),
        authorize: (endpoint, token, socketId, channelName) =>
            request(endpoint, {
                method: 'POST',
                token,
                body: { socket_id: socketId, channel_name: channelName },
            }),
    };
}
