import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { propertySchema } from './schemas';
import { useAuth } from '@/providers/auth-provider';

const schema = z.object({ data: z.object({ ids: z.array(z.int().positive()), imoveis: z.array(propertySchema) }) });
const resultSchema = z.object({ data: z.object({ id: z.int().positive(), favorito: z.boolean() }) });
export function useFavorites() {
  const { user, manager } = useAuth();
  const client = useQueryClient();
  const key = ['private', 'favorites', user?.id] as const;
  const query = useQuery({ queryKey: key, enabled: !!user, queryFn: ({ signal }) => manager.privateRequest('/favoritos', schema, signal) });
  const mutation = useMutation({
    mutationFn: ({ id, saved }: { id: number; saved: boolean }) => manager.privateRequest(`/favoritos/${id}/${saved ? 'salvar' : 'remover'}`, resultSchema, undefined, {}),
    onSuccess: async () => { if (manager.snapshot().user?.id === user?.id) await client.invalidateQueries({ queryKey: key }); },
  });
  return { query, mutation };
}
