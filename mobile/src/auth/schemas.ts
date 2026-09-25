import { z } from 'zod';
export const userSchema = z.object({ id: z.number().int().positive(), nome: z.string(), email: z.email(), telefone: z.string().nullable(), tipo_usuario: z.enum(['cliente', 'proprietario']) });
export type ClientUser = z.infer<typeof userSchema>;
const meta = z.object({ request_id: z.string() });
export const meSchema = z.object({ data: userSchema, meta });
export const sessionSchema = z.object({ data: z.object({ user: userSchema, access_token: z.string().regex(/^[a-f0-9]{64}$/), refresh_token: z.string().regex(/^[a-f0-9]{64}$/), token_type: z.literal('Bearer'), expires_in: z.number().nonnegative(), access_expires_at: z.string(), refresh_expires_at: z.string() }), meta });
export const logoutSchema = z.object({ data: z.object({ logged_out: z.literal(true) }), meta });
export type Session = z.infer<typeof sessionSchema>['data'];
