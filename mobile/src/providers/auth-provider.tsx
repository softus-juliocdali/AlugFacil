import { createContext, useContext, useEffect, useState, useSyncExternalStore, type PropsWithChildren } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { AppState } from 'react-native';
import { createHttpClient } from '@/api/http';
import { SessionManager } from '@/auth/session-manager';
import { refreshStorage } from '@/auth/secure-storage';

const Context = createContext<SessionManager | null>(null);
export function AuthProvider({ children }: PropsWithChildren) {
  const query = useQueryClient();
  const [manager] = useState(() => new SessionManager(refreshStorage, createHttpClient(() => process.env.EXPO_PUBLIC_API_URL), () => {
    const filters = { predicate: (q: { queryKey: readonly unknown[]; meta?: Record<string, unknown> }) => q.queryKey[0] === 'private' || q.meta?.private === true };
    void query.cancelQueries(filters);
    query.removeQueries(filters);
  }));
  useEffect(() => {
    void manager.restore();
    const subscription = AppState.addEventListener('change', state => { if (state === 'active' && manager.snapshot().error) void manager.restore(); });
    return () => subscription.remove();
  }, [manager]);
  return <Context.Provider value={manager}>{children}</Context.Provider>;
}
export function useAuth() {
  const manager = useContext(Context);
  if (!manager) throw new Error('AuthProvider is missing');
  return { ...useSyncExternalStore(manager.subscribe, manager.snapshot, manager.snapshot), manager };
}
