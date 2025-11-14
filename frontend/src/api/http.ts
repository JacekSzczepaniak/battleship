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
    const res = await fetch(`${BASE_URL}${path}`, {
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include', // jeśli sesje/cookies
        ...init,
    });

    const text = await res.text();
    const data = text ? JSON.parse(text) : null;

    if (!res.ok) {
        throw new ApiError(data?.message ?? 'API error', res.status, data);
    }
    return data as T;
}

export const http = {
    get: <T>(p: string) => request<T>(p),
    post: <T>(p: string, body?: unknown) =>
        request<T>(p, { method: 'POST', body: JSON.stringify(body ?? {}) }),
};
