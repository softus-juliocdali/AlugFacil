import { Redirect, useRouter } from 'expo-router';
import { ScrollView, View } from 'react-native';
import { Button, Copy, Loading, State } from '@/components/ui';
import { useAuth } from '@/providers/auth-provider';
import { ownerSections } from '@/owner/navigation';

export default function OwnerScreen() {
  const { user, restoring } = useAuth();
  const router = useRouter();
  if (restoring) return <Loading label="Verificando sua conta…" />;
  if (!user) return <Redirect href={{ pathname: '/acesso', params: { next: '/proprietario' } }} />;
  if (user.tipo_usuario !== 'proprietario') return <State title="Área do proprietário" description="Esta área exige uma conta de proprietário ativa." />;
  return <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ padding: 20, gap: 16, paddingBottom: 40, maxWidth: 640, width: '100%', alignSelf: 'center' }}>
    <Copy title>Área do proprietário</Copy><Copy>{user.nome}</Copy>
    <View style={{ gap: 12 }}>{ownerSections.map(item => <Button secondary key={item.path} label={item.title} onPress={() => router.push({ pathname: '/portal', params: { path: item.path } })} />)}</View>
    <Button label="Minhas reservas como hóspede" onPress={() => router.push({ pathname: '/portal', params: { path: '/cliente/historico' } })} />
    <Button secondary label="Reservar outro imóvel" onPress={() => router.navigate('/buscar')} />
    <Button secondary label="Minha conta e sair" onPress={() => router.navigate('/perfil')} />
  </ScrollView>;
}
