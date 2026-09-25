import { Tabs } from 'expo-router/js-tabs';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Icon, type IconName } from '@/components/icon';
import { colors, fonts } from '@/theme/tokens';

const tabs: { name: string; title: string; icon: IconName }[] = [
  { name: 'index', title: 'Início', icon: 'home' },
  { name: 'buscar', title: 'Buscar', icon: 'search' },
  { name: 'favoritos', title: 'Favoritos', icon: 'heart' },
  { name: 'reservas', title: 'Reservas', icon: 'calendar' },
  { name: 'perfil', title: 'Perfil', icon: 'user' },
];
export default function TabsLayout() {
  const insets = useSafeAreaInsets();
  return <Tabs initialRouteName="index" screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: colors.background }, tabBarActiveTintColor: colors.green800, tabBarInactiveTintColor: colors.muted, tabBarHideOnKeyboard: true, tabBarLabelStyle: { fontFamily: fonts.medium, fontSize: 11, lineHeight: 16 }, tabBarStyle: { backgroundColor: colors.white, borderTopColor: colors.line, paddingTop: 4, paddingBottom: Math.max(insets.bottom, 8), height: 72 + insets.bottom } }}>
    {tabs.map((tab) => <Tabs.Screen key={tab.name} name={tab.name} options={{ title: tab.title, tabBarIcon: ({ color, size }) => <Icon name={tab.icon} color={color} size={size} /> }} />)}
  </Tabs>;
}
