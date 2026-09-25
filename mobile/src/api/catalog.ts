import { createHttpClient } from './http';
import { detailSchema, homeSchema, listingSchema } from './schemas';

export const get = createHttpClient(() => process.env.EXPO_PUBLIC_API_URL);
export function listingPath(city: string, page: number, type = '') {
  const query = new URLSearchParams({ page: String(page), per_page: '12' });
  if (city.trim()) query.set('cidade', city.trim());
  if (type) query.set('tipo_imovel', type);
  return '/imoveis?' + query.toString();
}
export const catalog = {
  home: (signal?: AbortSignal) => get('/home?per_page=6', homeSchema, signal),
  list: (city: string, page: number, type: string, signal?: AbortSignal) => get(listingPath(city, page, type), listingSchema, signal),
  detail: (id: string, signal?: AbortSignal) => get('/imoveis/' + encodeURIComponent(id), detailSchema, signal),
};
