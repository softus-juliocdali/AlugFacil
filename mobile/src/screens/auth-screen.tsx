import { useState } from 'react';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Image } from 'expo-image';
import { Button, Copy } from '@/components/ui';
import { assets, colors, fonts, radius } from '@/theme/tokens';
import { useAuth } from '@/providers/auth-provider';
import { safeIntent } from '@/auth/intent';
import { ApiError } from '@/api/http';

export function AuthScreen({ kind }: { kind: 'login' | 'cadastro' }) {
  const registering = kind === 'cadastro';
  const { manager } = useAuth();
  const router = useRouter();
  const params = useLocalSearchParams<{ next?: string }>();
  const next = safeIntent(params.next);
  const insets = useSafeAreaInsets();
  const [values, setValues] = useState({ nome: '', telefone: '', email: '', senha: '', senha_confirmacao: '' });
  const [visible, setVisible] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fields, setFields] = useState<Record<string, string>>({});
  async function submit() {
    if (busy) return;
    setError(''); setFields({});
    const email = values.email.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !values.senha) { setError('Informe e-mail e senha válidos.'); return; }
    if (registering && (values.nome.trim().length < 2 || values.senha.length < 6 || values.senha !== values.senha_confirmacao)) {
      setError('Informe seu nome, uma senha com ao menos 6 caracteres e confirme a mesma senha.'); return;
    }
    setBusy(true);
    try {
      await manager.signIn(kind, registering ? { ...values, nome: values.nome.trim(), telefone: values.telefone.trim(), email } : { email, senha: values.senha });
      setValues({ nome: '', telefone: '', email: '', senha: '', senha_confirmacao: '' });
      router.dismissTo(next === '/perfil' && manager.snapshot().user?.tipo_usuario === 'proprietario' ? '/proprietario' : next);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Não foi possível entrar. Tente novamente.');
      if (e instanceof ApiError) setFields(e.fields);
    } finally { setBusy(false); }
  }
  function field(name: keyof typeof values, label: string, password = false) {
    return <View key={name} style={{ gap: 6 }}><Copy style={{ fontFamily: fonts.semibold }}>{label}</Copy>
      <TextInput accessibilityLabel={label} value={values[name]} editable={!busy} onChangeText={text => setValues(old => ({ ...old, [name]: text }))} secureTextEntry={password && !visible}
        autoCapitalize={name === 'nome' ? 'words' : 'none'} autoCorrect={false} keyboardType={name === 'email' ? 'email-address' : name === 'telefone' ? 'phone-pad' : 'default'}
        autoComplete={name === 'email' ? 'email' : name === 'telefone' ? 'tel' : name === 'nome' ? 'name' : registering ? 'new-password' : 'current-password'}
        maxLength={name === 'nome' ? 150 : name === 'telefone' ? 30 : name === 'email' ? 180 : 1024}
        style={{ minHeight: 52, padding: 14, borderWidth: 1, borderColor: fields[name] ? colors.danger : colors.line, borderRadius: radius.sm, backgroundColor: colors.white, color: colors.ink, fontFamily: fonts.regular, fontSize: 16 }} />
      {fields[name] && <Copy style={{ color: colors.danger }}>{fields[name]}</Copy>}</View>;
  }
  return <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}><ScrollView keyboardShouldPersistTaps="handled" contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ padding: 24, paddingBottom: insets.bottom + 32, gap: 20, width: '100%', maxWidth: 520, alignSelf: 'center' }}>
    <Image source={assets.logo} style={{ width: 150, height: 62, alignSelf: 'center' }} contentFit="contain" accessibilityLabel="AlugFácil" />
    <View style={{ gap: 8 }}><Copy title>{registering ? 'Seu próximo descanso começa aqui' : 'Bom ter você de volta'}</Copy><Copy style={{ color: colors.muted }}>{registering ? 'Crie sua conta de cliente para continuar.' : 'Entre como cliente ou proprietário com sua conta AlugFácil.'}</Copy></View>
    {registering && field('nome', 'Nome completo')}{registering && field('telefone', 'Telefone (opcional)')}
    {field('email', 'E-mail')}{field('senha', 'Senha', true)}{registering && field('senha_confirmacao', 'Confirmar senha', true)}
    <Pressable accessibilityRole="button" accessibilityLabel={visible ? 'Ocultar senha' : 'Mostrar senha'} onPress={() => setVisible(!visible)} style={{ minHeight: 44, justifyContent: 'center' }}><Copy style={{ color: colors.green800 }}>{visible ? 'Ocultar senha' : 'Mostrar senha'}</Copy></Pressable>
    {!!error && <View accessibilityRole="alert"><Copy style={{ color: colors.danger }}>{error}</Copy></View>}
    <Button disabled={busy} label={busy ? 'Aguarde…' : registering ? 'Criar conta' : 'Entrar'} onPress={() => void submit()} />
    <Button secondary disabled={busy} label={registering ? 'Já tenho conta' : 'Criar conta'} onPress={() => router.replace({ pathname: registering ? '/acesso' : '/cadastro', params: { next } })} />
    <Button secondary disabled={busy} label="Continuar explorando" onPress={() => router.replace('/')} />
  </ScrollView></KeyboardAvoidingView>;
}
