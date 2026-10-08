export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
    readonly status: number;
    readonly code: string;
    readonly fieldErrors: FieldErrors;
    readonly retryAfter: number | null;

    constructor(init: {
        message: string;
        status: number;
        code: string;
        fieldErrors?: FieldErrors;
        retryAfter?: number | null;
    }) {
        super(init.message);
        this.name = "ApiError";
        this.status = init.status;
        this.code = init.code;
        this.fieldErrors = init.fieldErrors ?? {};
        this.retryAfter = init.retryAfter ?? null;
    }
}

const REQUEST_TIMEOUT_MS = 15_000;

const MESSAGES = {
    network:
        "Impossible de joindre le serveur. Vérifiez votre connexion internet, puis réessayez.",
    timeout: "Le serveur met trop de temps à répondre. Réessayez dans un instant.",
    validation:
        "Certaines informations sont incorrectes. Corrigez les champs signalés, puis réessayez.",
    tooManyRequests: "Trop de tentatives. Patientez un instant avant de réessayer.",
    server:
        "Une erreur est survenue de notre côté. Réessayez dans quelques instants. Si le problème persiste, contactez le support.",
    unexpected: "Une erreur inattendue est survenue. Réessayez.",
} as const;

type RequestOptions = {
    method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
    body?: unknown;
    signal?: AbortSignal;
};

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === "object" && value !== null && !Array.isArray(value);
}

function getBaseUrl(): string {
    const url = process.env.NEXT_PUBLIC_API_URL;

    if (!url) {
        throw new Error(
            "La variable NEXT_PUBLIC_API_URL est manquante (voir frontend/.env.local).",
        );
    }

    return url.replace(/\/+$/, "");
}

async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function parseFieldErrors(value: unknown): FieldErrors {
    if (!isRecord(value)) return {};

    const result: FieldErrors = {};

    for (const [field, messages] of Object.entries(value)) {
        if (Array.isArray(messages)) {
            result[field] = messages.filter(
                (message): message is string => typeof message === "string",
            );
        }
    }

    return result;
}

function defaultCode(status: number): string {
    switch (status) {
        case 401:
            return "unauthenticated";
        case 403:
            return "forbidden";
        case 404:
            return "not_found";
        default:
            return "request_failed";
    }
}

function toApiError(status: number, payload: unknown): ApiError {
    const body = isRecord(payload) ? payload : {};
    const apiMessage = typeof body.message === "string" ? body.message : null;

    if (status === 422) {
        return new ApiError({
            message: MESSAGES.validation,
            status,
            code: "validation_error",
            fieldErrors: parseFieldErrors(body.errors),
        });
    }

    if (status === 429) {
        return new ApiError({
            message: apiMessage ?? MESSAGES.tooManyRequests,
            status,
            code: "too_many_requests",
            retryAfter: typeof body.retry_after === "number" ? body.retry_after : null,
        });
    }

    if (status >= 500) {
        return new ApiError({ message: MESSAGES.server, status, code: "server_error" });
    }

    return new ApiError({
        message: apiMessage ?? MESSAGES.unexpected,
        status,
        code: typeof body.code === "string" ? body.code : defaultCode(status),
    });
}

export async function apiRequest<T>(
    path: string,
    options: RequestOptions = {},
): Promise<T> {
    const { method = "GET", body, signal } = options;
    const timeout = AbortSignal.timeout(REQUEST_TIMEOUT_MS);

    let response: Response;

    try {
        response = await fetch(`${getBaseUrl()}${path}`, {
            method,
            headers: {
                Accept: "application/json",
                ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
            credentials: "include",
            signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
        });
    } catch (error) {
        if (signal?.aborted) {
            throw error;
        }

        if (error instanceof DOMException && error.name === "TimeoutError") {
            throw new ApiError({ message: MESSAGES.timeout, status: 0, code: "timeout" });
        }

        throw new ApiError({ message: MESSAGES.network, status: 0, code: "network_error" });
    }

    const payload = await readJson(response);

    if (response.ok) {
        return payload as T;
    }

    throw toApiError(response.status, payload);
}