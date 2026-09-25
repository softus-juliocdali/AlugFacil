import { Link } from 'expo-router';
import { Pressable, Text, View } from 'react-native';
import type { Property } from '@/api/schemas';
import { colors, fonts, radius } from '@/theme/tokens';
import { money, propertyType } from '@/utils/format';
import { Icon } from './icon';
import { RemoteImage } from './remote-image';
import { Copy } from './ui';

export function PropertyCard({ property }: { property: Property }) {
  return <Link href={{ pathname: '/imovel/[id]', params: { id: property.id } }} asChild>
    <Pressable accessibilityRole="link" accessibilityLabel={`Ver ${property.nome}, ${property.cidade}, ${money(property.preco.diaria_centavos)} por diária`} style={{ backgroundColor: colors.white, borderRadius: radius.md, borderWidth: 1, borderColor: colors.line, overflow: 'hidden' }}>
      <RemoteImage uri={property.imagem.url} label={property.nome} illustrative={property.imagem.ilustrativa} style={{ width: '100%', aspectRatio: 1.65 }} />
      <View style={{ padding: 18, gap: 9 }}>
        <Copy style={{ color: colors.green700, fontSize: 11, fontFamily: fonts.semibold, letterSpacing: 1 }}>{propertyType[property.tipo_imovel].toLocaleUpperCase('pt-BR')}</Copy>
        <Copy title style={{ fontSize: 20, lineHeight: 27 }}>{property.nome}</Copy>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 5 }}><Icon name="pin" size={15} /><Copy style={{ color: colors.muted, flex: 1, fontSize: 12 }}>{[property.cidade, property.regiao].filter(Boolean).join(' · ')}</Copy></View>
        <View style={{ borderTopWidth: 1, borderTopColor: colors.line, paddingTop: 12, marginTop: 3, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <View><Text style={{ fontFamily: fonts.bold, fontSize: 23, color: colors.green800 }}>{money(property.preco.diaria_centavos)}</Text><Copy style={{ fontSize: 11, color: colors.muted }}>por diária</Copy></View>
          {property.avaliacoes.quantidade > 0 ? <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}><Icon name="star" size={15} color={colors.orange} /><Copy style={{ fontSize: 12 }}>{property.avaliacoes.media.toFixed(1)} ({property.avaliacoes.quantidade})</Copy></View> : <Copy style={{ color: colors.muted, fontSize: 11 }}>Sem avaliações</Copy>}
          <Icon name="arrow" size={20} />
        </View>
      </View>
    </Pressable>
  </Link>;
}
