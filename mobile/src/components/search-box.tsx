import { useState } from 'react';
import { Keyboard, TextInput, View } from 'react-native';
import { colors, fonts, radius } from '@/theme/tokens';
import { Button, Copy } from './ui';
import { Icon } from './icon';

export function SearchBox({ initialValue = '', onSearch }: { initialValue?: string; onSearch: (city: string) => void }) {
  return <SearchForm key={initialValue} initialValue={initialValue} onSearch={onSearch} />;
}

function SearchForm({ initialValue, onSearch }: { initialValue: string; onSearch: (city: string) => void }) {
  const [city, setCity] = useState(initialValue);
  const submit = () => { Keyboard.dismiss(); onSearch(city.trim()); };
  return <View style={{ gap: 12 }}>
    <View style={{ borderWidth: 1, borderColor: colors.line, borderRadius: radius.sm, backgroundColor: colors.white, paddingHorizontal: 14, paddingVertical: 10, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
      <Icon name="pin" />
      <View style={{ flex: 1 }}><Copy style={{ fontFamily: fonts.semibold, fontSize: 11, color: colors.green800 }}>SEU DESTINO</Copy><TextInput accessibilityLabel="Cidade ou destino" placeholder="Para onde vamos?" placeholderTextColor={colors.muted} value={city} onChangeText={setCity} onSubmitEditing={submit} returnKeyType="search" maxLength={100} autoCorrect={false} style={{ color: colors.ink, fontFamily: fonts.regular, fontSize: 15, minHeight: 32, padding: 0 }} /></View>
    </View>
    <Button label="Buscar lugares" icon="search" onPress={() => submit()} style={{ backgroundColor: colors.orange }} />
  </View>;
}
