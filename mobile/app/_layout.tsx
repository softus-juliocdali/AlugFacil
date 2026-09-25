import { useFonts } from 'expo-font';
import { Inter_400Regular } from '@expo-google-fonts/inter/400Regular';
import { Inter_500Medium } from '@expo-google-fonts/inter/500Medium';
import { Inter_600SemiBold } from '@expo-google-fonts/inter/600SemiBold';
import { Inter_700Bold } from '@expo-google-fonts/inter/700Bold';
import { Stack } from 'expo-router/stack';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { QueryProvider } from '@/providers/query-provider';
import { AuthProvider } from '@/providers/auth-provider';
import { colors, fonts } from '@/theme/tokens';

export default function RootLayout() {
  // System fonts remain usable if loading the bundled font fails.
  useFonts({ Inter_400Regular, Inter_500Medium, Inter_600SemiBold, Inter_700Bold });
  return <SafeAreaProvider><QueryProvider><AuthProvider><StatusBar style="dark" />
    <Stack screenOptions={{ contentStyle: { backgroundColor: colors.background }, headerTintColor: colors.green800, headerTitleStyle: { fontFamily: fonts.semibold }, headerShadowVisible: false, headerBackButtonDisplayMode: 'minimal' }}>
      <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
      <Stack.Screen name="imovel/[id]" options={{ title: 'Conheça o lugar' }} />
      <Stack.Screen name="acesso" options={{ title: 'Sua conta', presentation: 'modal' }} />
      <Stack.Screen name="cadastro" options={{ title: 'Criar conta' }} />
      <Stack.Screen name="proprietario" options={{ title: 'Proprietário' }} />
      <Stack.Screen name="portal" options={{ title: 'AlugFácil' }} />
      <Stack.Screen name="+not-found" options={{ title: 'Página não encontrada' }} />
    </Stack>
  </AuthProvider></QueryProvider></SafeAreaProvider>;
}
