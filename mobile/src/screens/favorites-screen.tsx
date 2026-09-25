import { RefreshControl, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '@/providers/auth-provider';
import { useFavorites } from '@/api/favorites';
import { Button, Copy, Loading, State } from '@/components/ui';
import { PropertyCard } from '@/components/property-card';
import { GuestScreen } from './guest-screen';

export default function FavoritesScreen() {
  const { user } = useAuth();
  const { query, mutation } = useFavorites();
  const insets = useSafeAreaInsets();
  if (!user) return <GuestScreen kind="favorites" />;
  return <ScrollView contentContainerStyle={{ padding: 20, paddingTop: insets.top + 28, paddingBottom: 32, gap: 20 }} refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => void query.refetch()} />}>
    <Copy title>Favoritos</Copy>
    {query.isPending ? <Loading label="Carregando favoritos…" /> : query.isError ? <State title="Não foi possível atualizar os favoritos" description={query.error.message} action={() => void query.refetch()} /> : query.data.data.imoveis.length === 0 && <State icon="heart" title="Nenhum imóvel disponível nos favoritos" description="Abra um imóvel no catálogo e toque em Favoritar. Seus favoritos ficam salvos na sua conta." />}
    {mutation.isError && <Copy>{mutation.error.message}</Copy>}
    {query.data?.data.imoveis.map(property => <View key={property.id} style={{ gap: 8 }}><PropertyCard property={property} /><Button secondary label={`Remover ${property.nome} dos favoritos`} disabled={mutation.isPending} onPress={() => mutation.mutate({ id: property.id, saved: false })} /></View>)}
  </ScrollView>;
}
