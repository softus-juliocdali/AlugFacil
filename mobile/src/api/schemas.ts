import { z } from 'zod';

const image = z.object({ url: z.url().refine((s) => /^https?:\/\//.test(s)), ilustrativa: z.boolean() });
const pagination = z.object({ page: z.int().positive(), per_page: z.int().positive(), total: z.int().nonnegative(), total_pages: z.int().nonnegative() });
const meta = z.object({ request_id: z.string(), pagination });
export const propertySchema = z.object({
  id: z.int().positive(), nome: z.string(), tipo_imovel: z.enum(['chacara', 'sitio', 'area_lazer']),
  cidade: z.string(), regiao: z.string().nullable(),
  preco: z.object({ diaria_centavos: z.int().nonnegative(), moeda: z.literal('BRL'), unidade: z.literal('diaria') }),
  imagem: image, avaliacoes: z.object({ media: z.number().min(0).max(5), quantidade: z.int().nonnegative() }),
});
export const homeSchema = z.object({
  data: z.object({
    hero: z.object({ titulo: z.string(), descricao: z.string(), imagem_url: z.url(), logo_url: z.url() }),
    tipos_imovel: z.array(z.object({ id: z.string(), nome: z.string() })), imoveis: z.array(propertySchema),
  }), meta,
});
export const listingSchema = z.object({ data: z.array(propertySchema), meta });
const time = z.string().regex(/^([01][0-9]|2[0-3]):[0-5][0-9]$/).nullable();
export const detailSchema = z.object({
  data: propertySchema.extend({
    descricao: z.string(),
    localizacao: z.object({ cidade: z.string(), estado: z.string().nullable(), regiao: z.string().nullable(), endereco: z.string(), latitude: z.number().nullable(), longitude: z.number().nullable() }),
    horarios: z.object({ checkin_inicio: time, checkin_fim: time, checkout_inicio: time, checkout_fim: time }),
    galeria: z.array(image).min(1),
  }), meta: z.object({ request_id: z.string() }),
});
export type Property = z.infer<typeof propertySchema>;
export type PropertyDetail = z.infer<typeof detailSchema>['data'];
