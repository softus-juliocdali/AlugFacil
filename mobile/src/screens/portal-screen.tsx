import { useCallback, useEffect, useRef, useState } from 'react';
import { Redirect, useLocalSearchParams, useRouter } from 'expo-router';
import { BackHandler, Linking, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { z } from 'zod';
import { Button, Copy, Loading, State } from '@/components/ui';
import { useAuth } from '@/providers/auth-provider';
import { allowedDestination, allowedNavigation } from '@/owner/navigation';

const exchangeSchema = z.object({ data: z.object({ ticket: z.string().regex(/^[a-f0-9]{64}$/), expires_in: z.number() }), meta: z.object({ request_id: z.string() }) });
export default function PortalScreen() {
  const { path } = useLocalSearchParams<{ path: string }>();
  const { user, restoring, manager } = useAuth();
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const view = useRef<WebView>(null);
  const [exchange, setExchange] = useState<{ ticket: string; userId: number }>();
  const ticket = exchange?.userId === user?.id ? exchange?.ticket : undefined;
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [back, setBack] = useState(false);
  const valid = typeof path === 'string' && allowedDestination(path, user?.tipo_usuario);
  const origin = new URL(process.env.EXPO_PUBLIC_API_URL || 'https://alugfacil.net.br/api/v1').origin;
  const open = useCallback(async () => {
    setExchange(undefined); setError('');
    const userId = manager.snapshot().user?.id;
    try { const result = await manager.privateRequest('/mobile/web-session', exchangeSchema, undefined, { path }); if (userId) setExchange({ ticket: result.data.ticket, userId }); }
    catch { setError('Não foi possível abrir esta área. Confira sua conexão e tente novamente.'); }
  }, [manager, path]);
  useEffect(() => {
    if (!user || !valid) return;
    let active = true;
    void manager.privateRequest('/mobile/web-session', exchangeSchema, undefined, { path })
      .then(result => { if (active) setExchange({ ticket: result.data.ticket, userId: user.id }); })
      .catch(() => { if (active) setError('Não foi possível abrir esta área. Confira sua conexão e tente novamente.'); });
    return () => { active = false; };
  }, [manager, path, user, valid]);
  useEffect(() => { const sub = BackHandler.addEventListener('hardwareBackPress', () => { if (back) { view.current?.goBack(); return true; } return false; }); return () => sub.remove(); }, [back]);
  if (restoring) return <Loading label="Verificando sua conta…" />;
  if (!user) return <Redirect href="/acesso" />;
  if (!valid) return <State title="Destino indisponível" description="Escolha uma opção no menu da sua conta." />;
  function navigate(url: string) {
    if (url === 'about:blank') return true;
    if (url === `${origin}/logout`) {
      void manager.logout().then(() => router.replace('/perfil')).catch(() => setNotice('Não foi possível sair. Verifique a conexão e tente novamente.'));
      return false;
    }
    if (allowedNavigation(url, origin)) return true;
    // Only the boleto document may leave the app, never a hosted invoice or card form.
    if (/^https:\/\/(?:sandbox\.)?asaas\.com\/b\/pdf\//.test(url)) void Linking.openURL(url);
    else setNotice('Este destino externo não está disponível no aplicativo. Nenhuma cobrança adicional foi criada.');
    return false;
  }
  return <View style={{ flex: 1, paddingBottom: insets.bottom }}>
    <View style={{ flexDirection: 'row', gap: 8, padding: 8 }}><Button secondary label="Voltar" onPress={() => back ? view.current?.goBack() : router.back()} /><Button secondary label="Reabrir área" onPress={() => void open()} /></View>
    {!!notice && <Copy style={{ padding: 12 }}>{notice}</Copy>}
    {error ? <State icon="alert" title="Área indisponível" description={error} action={() => void open()} /> : !ticket ? <Loading label="Abrindo sua área segura…" /> : <WebView ref={view} key={ticket}
      source={{ uri: `${origin}/mobile/entrar`, method: 'POST', body: `ticket=${encodeURIComponent(ticket)}`, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }}
      originWhitelist={['https://*', ...(process.env.NODE_ENV === 'development' ? ['http://*'] : [])]}
      onShouldStartLoadWithRequest={event => navigate(event.url)}
      onOpenWindow={event => { if (!navigate(event.nativeEvent.targetUrl)) return; view.current?.injectJavaScript(`window.location.assign(${JSON.stringify(event.nativeEvent.targetUrl)});true;`); }}
      onNavigationStateChange={state => { setBack(state.canGoBack); if (new URL(state.url, origin).pathname === '/login') setError('Sua sessão expirou. Reabra esta área para continuar.'); }}
      onError={() => setError('Não foi possível carregar a página. Tente reabrir esta área.')}
      onHttpError={event => { if (event.nativeEvent.statusCode >= 400) setError('Página indisponível ou sem autorização. Volte ao menu ou reabra esta área.'); }}
      startInLoadingState renderLoading={() => <Loading label="Carregando…" />} incognito sharedCookiesEnabled={false} thirdPartyCookiesEnabled={false}
      mixedContentMode="never" allowFileAccess={false} javaScriptCanOpenWindowsAutomatically={false} webviewDebuggingEnabled={false} cacheEnabled={false}
    />}
  </View>;
}
