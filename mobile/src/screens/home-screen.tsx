import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { catalog } from '@/api/catalog';
import { Button, Copy, Loading, State } from '@/components/ui';
import { RemoteImage } from '@/components/remote-image';
import { SearchBox } from '@/components/search-box';
import { PropertyCard } from '@/components/property-card';
import { Icon } from '@/components/icon';
import { assets, colors, fonts, radius } from '@/theme/tokens';

export default function HomeScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const query = useQuery({ queryKey: ['home'], queryFn: ({ signal }) => catalog.home(signal) });
  const home = query.data?.data;
  return <ScrollView contentInsetAdjustmentBehavior="automatic" keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" refreshControl={<RefreshControl refreshing={query.isRefetching} onRefresh={() => void query.refetch()} tintColor={colors.green800} />} contentContainerStyle={{ paddingTop: insets.top + 12, paddingBottom: 28, gap: 24, width: '100%', maxWidth: 640, alignSelf: 'center' }}>
    <View style={{ paddingHorizontal: 20, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
      <RemoteImage uri={home?.hero.logo_url} fallback={assets.logo} label="AlugFácil" fit="contain" style={{ width: 145, height: 56, backgroundColor: 'transparent' }} />
      <Pressable accessibilityRole="button" accessibilityLabel="Ver perfil de visitante" onPress={() => router.navigate('/perfil')} style={{ padding: 11, borderRadius: 50, backgroundColor: colors.green100 }}><Icon name="user" size={21} /></Pressable>
    </View>
    <View style={{ marginHorizontal: 20, borderRadius: radius.lg, backgroundColor: colors.green100, overflow: 'hidden' }}>
      <View style={{ padding: 22, paddingBottom: 10, gap: 12 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 7 }}><Icon name="leaf" size={16} /><Copy style={{ fontFamily: fonts.semibold, color: colors.green800, fontSize: 10, letterSpacing: 1.6 }}>SEU PRÓXIMO RESPIRO</Copy></View>
        <Copy title style={{ fontSize: 28, lineHeight: 35, color: colors.green950, letterSpacing: -0.7 }}>{home?.hero.titulo ?? 'Seu próximo descanso começa aqui'}</Copy>
        <Copy style={{ color: colors.green900, fontSize: 13, lineHeight: 21 }}>{home?.hero.descricao ?? 'Explore lugares para viver bons momentos.'}</Copy>
      </View>
      <RemoteImage uri={home?.hero.imagem_url} fallback={assets.hero} label="Paisagem de uma chácara" fit="contain" style={{ width: '100%', aspectRatio: 1.9, backgroundColor: 'transparent' }} />
    </View>
    <View style={{ paddingHorizontal: 20 }}><SearchBox onSearch={(cidade) => router.navigate({ pathname: '/buscar', params: { cidade, tipo: '' } })} /></View>
    {home && <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 9, paddingHorizontal: 20 }}>
      {home.tipos_imovel.map((type) => <Pressable key={type.id} accessibilityRole="button" onPress={() => router.navigate({ pathname: '/buscar', params: { tipo: type.id, cidade: '' } })} style={{ backgroundColor: colors.white, borderColor: colors.line, borderWidth: 1, borderRadius: radius.pill, paddingHorizontal: 17, paddingVertical: 10 }}><Copy style={{ fontFamily: fonts.medium, fontSize: 12 }}>{type.nome}</Copy></Pressable>)}
    </ScrollView>}
    <View style={{ paddingHorizontal: 20, gap: 18 }}>
      <View style={{ gap: 3 }}><Copy style={{ fontSize: 10, letterSpacing: 1.8, fontFamily: fonts.semibold, color: colors.green700 }}>ESCOLHA SEU LUGAR</Copy><Copy title>Perto de bons momentos</Copy></View>
      {query.isPending ? <Loading /> : query.isError && !home ? <State icon="alert" title="Não conseguimos carregar" description={query.error.message} action={() => void query.refetch()} /> : <>
        {query.isRefetchError && <State icon="alert" title="Não foi possível atualizar" description="Você está vendo os últimos dados carregados." action={() => void query.refetch()} />}
        {home?.imoveis.length === 0 && <State title="Novos lugares vêm por aí" description="Não há imóveis disponíveis nesta busca. Tente outro destino ou volte mais tarde." actionLabel="Explorar destinos" action={() => router.navigate('/buscar')} />}
        {home?.imoveis.map((property) => <PropertyCard key={property.id} property={property} />)}
        {!!home?.imoveis.length && <Button secondary label="Ver todos os lugares" icon="arrow" onPress={() => router.navigate({ pathname: '/buscar', params: { cidade: '', tipo: '' } })} />}
      </>}
    </View>
    <View style={{ marginHorizontal: 20, padding: 22, borderTopWidth: 1, borderColor: colors.line, alignItems: 'center', gap: 6 }}><Icon name="leaf" size={22} /><Copy style={{ color: colors.green800, fontFamily: fonts.semibold }}>Desacelere. Aproveite. AlugFácil.</Copy><Copy style={{ color: colors.muted, fontSize: 11, textAlign: 'center' }}>Um lugar para os seus melhores momentos.</Copy></View>
  </ScrollView>;
}
