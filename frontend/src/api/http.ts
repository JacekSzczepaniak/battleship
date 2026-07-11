const BASE_URL = import.meta.env.VITE_API_URL as string;

export class ApiError extends Error {
    status: number;
    details?: unknown;
    constructor(message: string, status: number, details?: unknown) {
        super(message);
        this.status = status;
        this.details = details;
    }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
    const url = `${BASE_URL}${path}`;

    const res = await fetch(url, {
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include', // jeśli sesje/cookies
        ...init,
    });

    const text = await res.text();
    let data: any = null;
    try {
        data = text ? JSON.parse(text) : null;
    } catch (e) {
        // Nie-JSON odpowiedź – zalogujmy treść, by łatwiej było namierzyć
        try { console.warn('[http] non-JSON response', { url, status: res.status, text }); } catch {}
    }

    if (!res.ok) {
        const message = data?.error?.message ?? data?.message ?? 'API error';
        try { console.warn('[http] ← ERROR', res.status, url, { data }); } catch {}
        throw new ApiError(message, res.status, data);
    }

    return data as T;
}

export const http = {
    get: <T>(p: string) => request<T>(p),
    post: <T>(p: string, body?: unknown) =>
        request<T>(p, { method: 'POST', body: JSON.stringify(body ?? {}) }),
};
