import * as SecureStore from 'expo-secure-store';
const key = 'alugfacil.mobile.refresh.v1';
export const refreshStorage = {
  get: () => SecureStore.getItemAsync(key),
  set: (token: string) => SecureStore.setItemAsync(key, token, { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY }),
  remove: () => SecureStore.deleteItemAsync(key),
};
