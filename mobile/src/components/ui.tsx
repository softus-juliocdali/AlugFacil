import type { PropsWithChildren } from 'react';
import { ActivityIndicator, Pressable, Text, View, type TextStyle, type ViewStyle } from 'react-native';
import { colors, fonts, radius } from '@/theme/tokens';
import { Icon, type IconName } from './icon';

export function Copy({ children, style, title = false }: PropsWithChildren<{ style?: TextStyle; title?: boolean }>) {
  return <Text selectable style={[{ fontFamily: title ? fonts.bold : fonts.regular, fontSize: title ? 24 : 14, lineHeight: title ? 32 : 22, color: colors.ink }, style]}>{children}</Text>;
}
export function Button({ label, onPress, secondary = false, disabled = false, icon, style }: { label: string; onPress: () => void; secondary?: boolean; disabled?: boolean; icon?: IconName; style?: ViewStyle }) {
  return <Pressable accessibilityRole="button" accessibilityLabel={label} disabled={disabled} onPress={onPress} style={({ pressed }) => [{ minHeight: 50, paddingHorizontal: 20, paddingVertical: 13, borderRadius: radius.sm, backgroundColor: secondary ? colors.green100 : colors.green800, alignItems: 'center', justifyContent: 'center', flexDirection: 'row', gap: 10, opacity: disabled ? 0.5 : pressed ? 0.8 : 1 }, style]}>
    {icon && <Icon name={icon} color={secondary ? colors.green800 : colors.white} size={19} />}
    <Text style={{ fontFamily: fonts.semibold, fontSize: 14, color: secondary ? colors.green800 : colors.white, flexShrink: 1, textAlign: 'center' }}>{label}</Text>
  </Pressable>;
}
export function State({ title, description, icon = 'leaf', action, actionLabel = 'Tentar novamente' }: { title: string; description: string; icon?: IconName; action?: () => void; actionLabel?: string }) {
  return <View style={{ padding: 28, gap: 14, alignItems: 'center', backgroundColor: colors.white, borderWidth: 1, borderColor: colors.line, borderRadius: radius.md }} accessibilityLiveRegion="polite">
    <View style={{ backgroundColor: colors.green100, borderRadius: radius.pill, padding: 18 }}><Icon name={icon} size={30} /></View>
    <Copy title style={{ fontSize: 21, textAlign: 'center' }}>{title}</Copy>
    <Copy style={{ color: colors.muted, textAlign: 'center' }}>{description}</Copy>
    {action && <Button label={actionLabel} onPress={action} />}
  </View>;
}
export function Loading({ label = 'Buscando seu próximo destino…' }: { label?: string }) {
  return <View accessibilityRole="progressbar" accessibilityLabel={label} style={{ gap: 18, paddingVertical: 24 }}>
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}><ActivityIndicator color={colors.green800} accessible={false} accessibilityElementsHidden importantForAccessibility="no-hide-descendants" /><Copy style={{ color: colors.muted }}>{label}</Copy></View>
    {[0, 1].map((i) => <View key={i} style={{ height: 200, borderRadius: radius.md, backgroundColor: colors.green100, opacity: i ? 0.45 : 0.8 }} />)}
  </View>;
}
