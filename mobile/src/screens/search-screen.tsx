import { useInfiniteQuery } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { FlatList, RefreshControl, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { catalog } from '@/api/catalog';
import { Button, Copy, Loading, State } from '@/components/ui';
import { SearchBox } from '@/components/search-box';
import { PropertyCard } from '@/components/property-card';
import { colors, fonts } from '@/theme/tokens';
import { propertyType } from '@/utils/format';

export default function SearchScreen() {
  const params = useLocalSearchParams<{ cidade?: string; tipo?: string }>();
  const city = typeof params.cidade === 'string' ? params.cidade : '';
  const type = typeof params.tipo === 'string' && params.tipo in propertyType ? params.tipo : '';
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const queryKey = ['properties', city, type] as const;
  const query = useInfiniteQuery({ queryKey, initialPageParam: 1, queryFn: ({ pageParam, signal }) => {
    return catalog.list(city, pageParam, type, signal);
  }, getNextPageParam: (last) => last.meta.pagination.page < last.meta.pagination.total_pages ? last.meta.pagination.page + 1 : undefined });
  const items = [...new Map((query.data?.pages.flatMap((page) => page.data) ?? []).map((item) => [item.id, item])).values()];
  return <FlatList data={items} keyExtractor={(item) => String(item.id)} renderItem={({ item }) => <PropertyCard property={item} />} ItemSeparatorComponent={() => <View style={{ height: 18 }} />} contentInsetAdjustmentBehavior="automatic" keyboardShouldPersistTaps="handled" keyboardDismissMode="on-drag" refreshControl={<RefreshControl refreshing={query.isRefetching && !query.isFetchingNextPage} onRefresh={() => void query.refetch()} tintColor={colors.green800} />} contentContainerStyle={{ paddingHorizontal: 20, paddingTop: insets.top + 28, paddingBottom: 28, width: '100%', maxWidth: 640, alignSelf: 'center' }}
    ListHeaderComponent={<View style={{ gap: 20, paddingBottom: 22 }}><View style={{ gap: 5 }}><Copy title>Encontre seu refúgio</Copy><Copy style={{ color: colors.muted }}>Qual será o destino do próximo descanso?</Copy></View><SearchBox initialValue={city} onSearch={(cidade) => router.setParams({ cidade })} />
      {(city || type) && <Button secondary label="Limpar busca e filtros" onPress={() => router.setParams({ cidade: '', tipo: '' })} />}
      <Copy style={{ fontFamily: fonts.semibold }}>{query.data ? `${query.data.pages[0]?.meta.pagination.total ?? 0} lugares encontrados` : 'Explorar lugares'}{type ? ` · ${propertyType[type as keyof typeof propertyType]}` : ''}</Copy>
      {query.isRefetchError && items.length > 0 && <State icon="alert" title="Não foi possível atualizar" description="Os resultados anteriores foram mantidos." action={() => void query.refetch()} />}
    </View>}
    ListEmptyComponent={query.isPending ? <Loading /> : query.isError ? <State icon="alert" title="Não conseguimos buscar" description={query.error.message} action={() => void query.refetch()} /> : <State title="Nenhum lugar por aqui ainda" description="Tente buscar por outra cidade ou limpe os filtros para explorar mais destinos." />}
    ListFooterComponent={<View style={{ gap: 14, paddingTop: 20 }}>{query.isFetchNextPageError && <Copy style={{ color: colors.danger }}>Não foi possível carregar mais lugares. Tente novamente.</Copy>}{query.hasNextPage && <Button secondary label={query.isFetchingNextPage ? 'Carregando…' : 'Carregar mais lugares'} disabled={query.isFetchingNextPage} onPress={() => void query.fetchNextPage()} />}</View>}
  />;
}
