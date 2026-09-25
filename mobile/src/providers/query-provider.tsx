import { useEffect, useState, type PropsWithChildren } from 'react';
import { AppState } from 'react-native';
import { QueryClient, QueryClientProvider, focusManager } from '@tanstack/react-query';
import { ApiError } from '@/api/http';

export function QueryProvider({ children }: PropsWithChildren) {
  const [client] = useState(() => new QueryClient({ defaultOptions: { queries: {
    staleTime: 60000, gcTime: 300000,
    retry: (count, error) => count < 1 && !(error instanceof ApiError && (error.status >= 400 && error.status < 500 || error.code === 'CONFIGURATION_ERROR' || error.code === 'INVALID_RESPONSE')),
  } } }));
  useEffect(() => {
    if (process.env.EXPO_OS === 'web') return;
    const subscription = AppState.addEventListener('change', (state) => focusManager.setFocused(state === 'active'));
    return () => subscription.remove();
  }, []);
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
