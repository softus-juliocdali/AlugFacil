import type { z } from 'zod';
import { ApiError, createHttpClient } from '../api/http';
import { logoutSchema, meSchema, sessionSchema, type ClientUser, type Session } from './schemas';

type Storage = { get(): Promise<string | null>; set(token: string): Promise<void>; remove(): Promise<void> };
export type AuthState = { user: ClientUser | null; restoring: boolean; error: string | null };
export class SessionManager {
  private state: AuthState = { user: null, restoring: true, error: null };
  private listeners = new Set<() => void>();
  private access: string | null = null;
  private refresh: string | null = null;
  private flight: Promise<void> | null = null;
  private signingIn = false;
  private epoch = 0;
  private storageQueue = Promise.resolve();
  constructor(private storage: Storage, private http: ReturnType<typeof createHttpClient>, private clearPrivate: () => void) {}
  snapshot = () => this.state;
  subscribe = (listener: () => void) => { this.listeners.add(listener); return () => { this.listeners.delete(listener); }; };
  private publish(patch: Partial<AuthState>) { this.state = { ...this.state, ...patch }; this.listeners.forEach(fn => fn()); }
  private store(action: () => Promise<void>) {
    const task = this.storageQueue.catch(() => undefined).then(action);
    this.storageQueue = task;
    return task;
  }
  private async accept(session: Session, epoch: number) {
    if (epoch !== this.epoch) return;
    await this.store(() => this.storage.set(session.refresh_token));
    if (epoch !== this.epoch) return;
    if (this.state.user?.id !== session.user.id) { this.clearPrivate(); this.publish({ user: null }); }
    this.access = session.access_token;
    this.refresh = session.refresh_token;
  }
  private async forget() {
    ++this.epoch;
    this.access = this.refresh = null;
    this.clearPrivate();
    this.publish({ user: null, restoring: false, error: null });
    await this.store(() => this.storage.remove());
  }
  async restore() {
    if (this.signingIn) return;
    const epoch = this.epoch;
    try {
      if (this.flight) { await this.flight; return; }
      const token = await this.storage.get();
      if (epoch !== this.epoch) return;
      this.refresh = token;
      if (token) await this.renew();
    } catch (error) {
      if (epoch === this.epoch) this.publish({ error: error instanceof Error ? error.message : 'Não foi possível restaurar sua conta.' });
    } finally { if (epoch === this.epoch) this.publish({ restoring: false }); }
  }
  async signIn(kind: 'login' | 'cadastro', input: Record<string, string>) {
    if (this.signingIn) throw new ApiError('Aguarde a tentativa atual.', 0, 'AUTH_IN_PROGRESS');
    this.signingIn = true;
    this.publish({ error: null });
    const epoch = ++this.epoch;
    try {
    // A restore already in progress must finish before credentials replace the stored token.
    await this.flight?.catch(() => undefined);
    const result = await this.http(`/auth/${kind}`, sessionSchema, undefined, undefined, input);
    await this.accept(result.data, epoch);
    if (epoch !== this.epoch) return;
    try {
      const me = await this.http('/me', meSchema, undefined, { Authorization: `Bearer ${this.access}` });
      if (epoch === this.epoch) this.publish({ user: me.data, restoring: false, error: null });
    } catch (error) {
      if (epoch === this.epoch) {
        if (error instanceof ApiError && [401, 403].includes(error.status)) await this.forget();
        else this.publish({ error: 'Não foi possível verificar sua conta. Tente restaurar a sessão no perfil.' });
      }
      throw error;
    }
    } finally {
      this.signingIn = false;
      if (epoch === this.epoch) this.publish({ restoring: false });
    }
  }
  renew(): Promise<void> {
    if (this.flight) return this.flight;
    const epoch = this.epoch;
    this.flight = (async () => {
      if (!this.refresh) throw new ApiError('Entre para continuar.', 401, 'UNAUTHENTICATED');
      try {
        const result = await this.http('/auth/refresh', sessionSchema, undefined, undefined, { refresh_token: this.refresh });
        await this.accept(result.data, epoch);
        if (epoch !== this.epoch) return;
        const me = await this.http('/me', meSchema, undefined, { Authorization: `Bearer ${this.access}` });
        if (epoch === this.epoch) this.publish({ user: me.data, restoring: false, error: null });
      } catch (error) {
        if (epoch === this.epoch && error instanceof ApiError && [401, 403].includes(error.status)) await this.forget();
        throw error;
      }
    })().finally(() => { this.flight = null; });
    return this.flight;
  }
  async privateRequest<T>(path: string, schema: z.ZodType<T>, signal?: AbortSignal, body?: Record<string, string>): Promise<T> {
    const epoch = this.epoch;
    if (!this.access) await this.renew();
    const used = this.access;
    try {
      const result = await this.http(path, schema, signal, { Authorization: `Bearer ${used}` }, body);
      if (epoch !== this.epoch) throw new ApiError('A sessão mudou.', 401, 'SESSION_CHANGED');
      return result;
    }
    catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401 || epoch !== this.epoch) throw error;
      // A peer may already have renewed while this request was in flight.
      if (this.access === used) await this.renew();
      if (epoch !== this.epoch) throw error;
      try {
        const result = await this.http(path, schema, signal, { Authorization: `Bearer ${this.access}` }, body);
        if (epoch !== this.epoch) throw new ApiError('A sessão mudou.', 401, 'SESSION_CHANGED');
        return result;
      }
      catch (retryError) {
        if (epoch === this.epoch && retryError instanceof ApiError && retryError.status === 401) await this.forget();
        throw retryError;
      }
    }
  }
  async logout() {
    // Online revocation must succeed before reporting a complete logout. Offline keeps
    // the credential for retry, instead of silently leaving an active remote session.
    await this.flight?.catch(() => undefined);
    if (this.refresh) {
      try { await this.http('/auth/logout', logoutSchema, undefined, undefined, { refresh_token: this.refresh }); }
      catch (error) { if (!(error instanceof ApiError) || ![401, 403].includes(error.status)) throw error; }
    }
    await this.forget();
  }
}
