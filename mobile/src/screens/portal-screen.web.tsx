import { useState } from 'react';
import { useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';
import { z } from 'zod';
import { Button, Copy } from '@/components/ui';
import { useAuth } from '@/providers/auth-provider';
import { allowedDestination } from '@/owner/navigation';

// Browser preview uses a top-level POST: production blocks embedding by other origins.
export default function PortalWebScreen() {
  const { path } = useLocalSearchParams<{ path: string }>();
  const { user, manager } = useAuth();
  const [error,setError] = useState('');
  async function open() {
    if (!user || !allowedDestination(path,user.tipo_usuario)) return;
    try {
      const schema=z.object({ data:z.object({ ticket:z.string().regex(/^[a-f0-9]{64}$/) }) });
      const { data }=await manager.privateRequest('/mobile/web-session',schema,undefined,{ path });
      const form=document.createElement('form');form.method='POST';form.action=new URL('/mobile/entrar',process.env.EXPO_PUBLIC_API_URL).href;
      const input=document.createElement('input');input.type='hidden';input.name='ticket';input.value=data.ticket;form.append(input);document.body.append(form);form.submit();form.remove();
    } catch { setError('Não foi possível abrir a área. Tente novamente.'); }
  }
  return <View style={{padding:24,gap:16}}><Copy title>Área integrada AlugFácil</Copy><Copy>No iOS e Android esta tela abre dentro do aplicativo. Nesta prévia de navegador, continue no site autenticado.</Copy><Button label="Continuar na área segura" disabled={!user || !allowedDestination(path,user.tipo_usuario)} onPress={()=>void open()} />{!!error&&<Copy>{error}</Copy>}</View>;
}
