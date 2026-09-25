import { useRouter } from 'expo-router';
import { ScrollView } from 'react-native';
import { State } from '@/components/ui';
export default function NotFound() {
  const router = useRouter();
  return <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ padding: 24 }}><State title="Caminho não encontrado" description="Volte ao início para encontrar um lugar especial." actionLabel="Ir para o início" action={() => router.replace('/')} /></ScrollView>;
}
