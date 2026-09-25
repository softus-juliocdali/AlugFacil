import { useQuery } from '@tanstack/react-query';
import { Stack } from 'expo-router/stack';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { RefreshControl, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { catalog } from '@/api/catalog';
import { Button, Copy, Loading, State } from '@/components/ui';
import { RemoteImage } from '@/components/remote-image';
import { Icon } from '@/components/icon';
import { colors, fonts, radius } from '@/theme/tokens';
import { hours, money, propertyType } from '@/utils/format';
import { useAuth } from '@/providers/auth-provider';
import { useFavorites } from '@/api/favorites';

export default function DetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const valid = typeof id === 'string' && /^[1-9]\d{0,9}$/.test(id) && Number(id) <= 2147483647;
  const query = useQuery({ queryKey: ['property', id], queryFn: ({ signal }) => catalog.detail(id, signal), enabled: valid });
  const property = query.data?.data;
  const router = useRouter();
  const { user } = useAuth();
  const favorites = useFavorites();
  const saved = favorites.query.data?.data.ids.includes(Number(id)) ?? false;
  const insets = useSafeAreaInsets();
  return <ScrollView contentInsetAdjustmentBehavior="automatic" refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => { if (valid) void query.refetch(); }} tintColor={colors.green800} />} contentContainerStyle={{ padding: 20, paddingBottom: insets.bottom + 28, gap: 20, width: '100%', maxWidth: 640, alignSelf: 'center' }}>
    <Stack.Screen options={{ title: property?.nome ?? 'Conheça o lugar' }} />
    {!valid ? <State title="Imóvel não encontrado" description="Explore outros lugares disponíveis no catálogo." actionLabel="Explorar" action={() => router.replace('/buscar')} /> : query.isPending ? <Loading label="Conhecendo este lugar…" /> : query.isError && !property ? <State icon="alert" title="Imóvel indisponível" description={query.error.message} action={() => void query.refetch()} /> : property && <>
      {query.isRefetchError && <State icon="alert" title="Não foi possível atualizar" description="Os dados exibidos foram carregados anteriormente." action={() => void query.refetch()} />}
      <RemoteImage uri={property.galeria[0]?.url} illustrative={property.galeria[0]?.ilustrativa} label={property.nome} style={{ width: '100%', aspectRatio: 1.2, borderRadius: radius.md }} />
      <View style={{ gap: 8 }}><Copy style={{ color: colors.green700, fontFamily: fonts.semibold }}>{propertyType[property.tipo_imovel]}</Copy><Copy title style={{ color: colors.green950, fontSize: 28, lineHeight: 36 }}>{property.nome}</Copy><View style={{ flexDirection: 'row', gap: 6, alignItems: 'center' }}><Icon name="pin" size={18} /><Copy style={{ flex: 1, color: colors.muted }}>{[property.localizacao.cidade, property.localizacao.estado, property.regiao].filter(Boolean).join(' · ')}</Copy></View>
        {property.avaliacoes.quantidade > 0 && <Copy>★ {property.avaliacoes.media.toFixed(1)} · {property.avaliacoes.quantidade} avaliações</Copy>}
      </View>
      <View style={{ padding: 20, gap: 5, borderRadius: radius.md, backgroundColor: colors.green100 }}><Copy style={{ fontSize: 12, color: colors.green800 }}>DIÁRIA BASE</Copy><Copy title style={{ color: colors.green800, fontSize: 30, lineHeight: 40 }}>{money(property.preco.diaria_centavos)}</Copy><Copy style={{ color: colors.muted, fontSize: 12 }}>Valor por diária. Não é uma cotação de reserva.</Copy></View>
      <View style={{ gap: 9 }}><Copy title style={{ fontSize: 21 }}>Sobre este lugar</Copy><Copy style={{ color: colors.muted }}>{property.descricao || 'Descrição não informada.'}</Copy></View>
      {property.localizacao.endereco !== '' && <View style={{ gap: 8 }}><Copy title style={{ fontSize: 21 }}>Localização</Copy><Copy style={{ color: colors.muted }}>{property.localizacao.endereco}</Copy></View>}
      <View style={{ padding: 18, gap: 14, borderWidth: 1, borderColor: colors.line, borderRadius: radius.md }}><View style={{ flexDirection: 'row', gap: 8 }}><Icon name="clock" /><Copy title style={{ fontSize: 19 }}>Horários</Copy></View><Copy>Entrada: {hours(property.horarios.checkin_inicio, property.horarios.checkin_fim)}</Copy><Copy>Saída: {hours(property.horarios.checkout_inicio, property.horarios.checkout_fim)}</Copy></View>
      {property.galeria.slice(1).map((photo, index) => <RemoteImage key={`${photo.url}-${index}`} uri={photo.url} illustrative={photo.ilustrativa} label={`Foto ${index + 2} de ${property.nome}`} style={{ width: '100%', aspectRatio: 1.5, borderRadius: radius.md }} />)}
      <Button label="Reservar" onPress={() => user ? router.push({ pathname: '/portal', params: { path: `/reserva/criar/${id}` } }) : router.push({ pathname: '/acesso', params: { next: `/imovel/${id}` } })} />
      <Button secondary label={saved ? 'Desfavoritar' : 'Favoritar'} disabled={!!user && (favorites.query.isPending || favorites.query.isError || favorites.mutation.isPending)} onPress={() => user ? favorites.mutation.mutate({ id: Number(id), saved: !saved }) : router.push({ pathname: '/acesso', params: { next: `/imovel/${id}` } })} />
      {favorites.query.isError && user && <State title="Não foi possível consultar seus favoritos" description={favorites.query.error.message} action={() => void favorites.query.refetch()} />}
      {favorites.mutation.isError && <Copy>{favorites.mutation.error.message}</Copy>}
      <Copy style={{ color: colors.muted, fontSize: 12, textAlign: 'center' }}>{user ? 'Escolha as datas e confira o valor total antes de confirmar a reserva.' : 'Entre na sua conta para continuar.'}</Copy>
    </>}
  </ScrollView>;
}
