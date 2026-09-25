// Browser preview only: never downgrade native secrets to unencrypted persistent storage.
let token: string | null = null;
export const refreshStorage = {
  get: async () => token,
  set: async (value: string) => { token = value; },
  remove: async () => { token = null; },
};
