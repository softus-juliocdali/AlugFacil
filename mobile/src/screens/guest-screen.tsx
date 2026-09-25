import { useRouter } from 'expo-router';
import { ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Image } from 'expo-image';
import { Button, Copy, State } from '@/components/ui';
import { assets, colors } from '@/theme/tokens';
import type { IconName } from '@/components/icon';
import { useState } from 'react';
import { useAuth } from '@/providers/auth-provider';

const content = {
  favorites: { heading: 'Favoritos', title: 'Guarde seus lugares favoritos', text: 'Entre na sua conta para continuar.', icon: 'heart' },
  reservations: { heading: 'Reservas', title: 'Seus próximos bons momentos', text: 'Entre na sua conta para continuar.', icon: 'calendar' },
  profile: { heading: 'Seu perfil', title: 'Bem-vindo ao AlugFácil', text: 'Explore destinos livremente ou entre na sua conta.', icon: 'user' },
};
export function GuestScreen({ kind }: { kind: keyof typeof content }) {
  const c = content[kind];
  const insets = useSafeAreaInsets();
  const router = useRouter();
  const { user, restoring, error: restoreError, manager } = useAuth();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const next = kind === 'favorites' ? '/favoritos' : kind === 'reservations' ? '/reservas' : '/perfil';
  async function logout() { setBusy(true); setError(''); try { await manager.logout(); } catch (e) { setError(e instanceof Error ? e.message : 'Não foi possível sair.'); } finally { setBusy(false); } }
  if (kind === 'reservations' && user) return <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ padding: 24, paddingTop: insets.top + 28, gap: 24 }}>
    <Copy title>Minhas reservas</Copy><Copy>Acompanhe suas reservas como hóspede, pagamentos e detalhes de cada estadia.</Copy>
    <Button label="Ver minhas reservas" onPress={() => router.push({ pathname: '/portal', params: { path: '/cliente/historico' } })} />
  </ScrollView>;
  if (kind !== 'profile' && !user) {
    if (restoring) return <View style={{ padding: 24, paddingTop: insets.top + 28 }}><Copy>Verificando sua sessão…</Copy></View>;
    // Keep inactive tabs passive; navigation starts only from the user's button.
  }
  if (user) return <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ padding: 24, paddingTop: insets.top + 28, gap: 24 }}>
    <Copy title>{c.heading}</Copy>
    {kind === 'profile' && user.tipo_usuario === 'proprietario' && <Button label="Área do proprietário" onPress={() => router.push('/proprietario')} />}
    {kind === 'profile' ? <><Copy title>{user.nome}</Copy><Copy>{user.email}</Copy><Copy>{user.telefone || 'Telefone não informado'}</Copy>{!!error && <Copy style={{ color: colors.danger }}>{error}</Copy>}<Button label={busy ? 'Saindo…' : 'Sair da conta'} disabled={busy} onPress={() => void logout()} /></>
      : <State icon={c.icon as IconName} title={kind === 'favorites' ? 'Seus favoritos aparecerão aqui.' : 'Suas reservas aparecerão aqui.'} description="Esta funcionalidade estará disponível em uma próxima etapa." />}
  </ScrollView>;
  return <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ padding: 20, paddingTop: insets.top + 28, paddingBottom: 32, gap: 24, width: '100%', maxWidth: 640, alignSelf: 'center' }}>
    <Copy title>{c.heading}</Copy>
    <View style={{ alignItems: 'center', paddingVertical: 16 }}><Image source={assets.logo} style={{ width: 180, height: 75 }} contentFit="contain" accessibilityLabel="AlugFácil" /><Copy style={{ color: colors.muted }}>Navegando como visitante</Copy></View>
    <State icon={c.icon as IconName} title={c.title} description={c.text} />
    {restoring && <Copy>Verificando sua sessão…</Copy>}
    {!!restoreError && <State icon="alert" title="Sua sessão não foi restaurada" description={restoreError} action={() => void manager.restore()} />}
    <Button label="Entrar" onPress={() => router.push({ pathname: '/acesso', params: { next } })} />
    {kind === 'profile' && <Button secondary label="Criar conta" onPress={() => router.push({ pathname: '/cadastro', params: { next } })} />}
    <Button secondary label="Explorar lugares" icon="search" onPress={() => router.navigate('/buscar')} />
  </ScrollView>;
}
