import { useState } from 'react';
import { View, type ViewStyle } from 'react-native';
import { Image, type ImageSource } from 'expo-image';
import { colors } from '@/theme/tokens';
import { Icon } from './icon';
import { Copy } from './ui';

export function RemoteImage({ uri, style, label, fallback, fit = 'cover', illustrative = false }: { uri?: string; style: ViewStyle; label: string; fallback?: ImageSource; fit?: 'cover' | 'contain'; illustrative?: boolean }) {
  const [failedUri, setFailedUri] = useState<string>();
  const failed = !uri || failedUri === uri;
  return <View style={[{ overflow: 'hidden', backgroundColor: colors.green100 }, style]}>
    {failed && !fallback ? <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', gap: 8 }}><Icon name="photo" size={32} /><Copy style={{ color: colors.muted }}>Imagem indisponível</Copy></View> :
      <Image source={failed ? fallback : { uri }} style={{ width: '100%', height: '100%' }} contentFit={fit} accessibilityLabel={label} onError={() => setFailedUri(uri)} transition={180} />}
    {illustrative && !failed && <View style={{ position: 'absolute', bottom: 10, left: 12, paddingHorizontal: 8, paddingVertical: 3, backgroundColor: colors.white, borderRadius: 6 }}><Copy style={{ fontSize: 10, lineHeight: 15 }}>Imagem ilustrativa</Copy></View>}
  </View>;
}
