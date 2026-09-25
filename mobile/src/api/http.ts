import type { z } from 'zod';

export class ApiError extends Error {
  constructor(message: string, public readonly status = 0, public readonly code = 'NETWORK_ERROR', public readonly fields: Record<string, string> = {}) {
    super(message);
    this.name = 'ApiError';
  }
}

export function apiBase(value: string | undefined): string {
  try {
    const url = new URL(value ?? '');
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.search || url.hash || !url.pathname.replace(/\/$/, '').endsWith('/api/v1')) throw new Error();
    return url.toString().replace(/\/$/, '');
  } catch {
    throw new ApiError('Configure a URL da API para conectar o aplicativo.', 0, 'CONFIGURATION_ERROR');
  }
}

export type HttpTransport = (input: string, init?: RequestInit) => Promise<Response>;
export function createHttpClient(base: () => string | undefined, transport: HttpTransport = fetch) {
  return async function request<T>(path: string, schema: z.ZodType<T>, signal?: AbortSignal, headers?: HeadersInit, body?: Record<string, string>): Promise<T> {
    if (!/^\/[a-z0-9/-]+(?:\?[^#]*)?$/i.test(path)) throw new ApiError('Rota inválida.', 0, 'CONFIGURATION_ERROR');
    const url = apiBase(base()) + path;
    const controller = new AbortController();
    const cancel = () => controller.abort();
    if (signal?.aborted) cancel();
    signal?.addEventListener('abort', cancel, { once: true });
    const timeout = setTimeout(cancel, 15000);
    try {
      const requestHeaders = new Headers(headers);
      requestHeaders.set('Accept', 'application/json');
      if (body) requestHeaders.set('Content-Type', 'application/json');
      const response = await transport(url, { method: body ? 'POST' : 'GET', body: body ? JSON.stringify(body) : undefined, headers: requestHeaders, credentials: 'omit', redirect: 'error', signal: controller.signal });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new ApiError('O serviço retornou uma resposta inesperada.', response.status, 'INVALID_RESPONSE');
      const responseBody: unknown = await response.json();
      if (!response.ok) {
        const message = response.status === 404 ? 'Este imóvel não está disponível.' : response.status === 422 ? 'Confira os dados da busca e tente novamente.' : 'Não foi possível carregar agora. Tente novamente.';
        const detail = responseBody && typeof responseBody === 'object' && 'error' in responseBody ? responseBody.error as { message?: unknown; code?: unknown; fields?: unknown } : null;
        const fields = detail?.fields && typeof detail.fields === 'object' ? Object.fromEntries(Object.entries(detail.fields).filter((entry): entry is [string, string] => typeof entry[1] === 'string')) : {};
        const authError = path.startsWith('/auth/') || path === '/me';
        const safeMessage = authError && response.status >= 400 && response.status < 500 && typeof detail?.message === 'string' ? detail.message : message;
        throw new ApiError(safeMessage, response.status, typeof detail?.code === 'string' ? detail.code : 'HTTP_ERROR', authError && response.status < 500 ? fields : {});
      }
      const parsed = schema.safeParse(responseBody);
      if (!parsed.success) throw new ApiError('O serviço retornou dados incompatíveis. Tente novamente mais tarde.', response.status, 'INVALID_RESPONSE');
      return parsed.data;
    } catch (error) {
      if (error instanceof ApiError) throw error;
      if (signal?.aborted) throw error;
      throw new ApiError('Não foi possível conectar. Confira sua conexão e tente novamente.');
    } finally {
      clearTimeout(timeout);
      signal?.removeEventListener('abort', cancel);
    }
  };
}
