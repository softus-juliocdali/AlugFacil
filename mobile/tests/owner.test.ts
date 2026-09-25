import { describe, it, expect } from 'vitest';
import { allowedDestination, allowedNavigation, ownerSections } from '../src/owner/navigation';
import { userSchema } from '../src/auth/schemas';
describe('Owner integration boundary', () => {
  it('admits the original owner role without granting it to clients', () => {
    const user={id:12,nome:'Teste',email:'owner@example.test',telefone:null,tipo_usuario:'proprietario'};
    expect(userSchema.parse(user).tipo_usuario).toBe('proprietario');
    expect(userSchema.safeParse({...user,tipo_usuario:'admin'}).success).toBe(false);
    for(const section of ownerSections){expect(allowedDestination(section.path,'proprietario')).toBe(true);expect(allowedDestination(section.path,'cliente')).toBe(false);}
  });
  it('preserves guest access without exposing private checkout as a bridge destination', () => {
    for(const role of ['cliente','proprietario']){expect(allowedDestination('/cliente/historico',role)).toBe(true);expect(allowedDestination('/reserva/criar/5',role)).toBe(true);}
    for(const path of ['/reserva/confirmacao/2','//evil.test','/admin','/proprietario/../admin','/proprietario/dashboard?x=1'])expect(allowedDestination(path,'proprietario')).toBe(false);
  });
  it('never navigates a WebView to a hosted invoice or another origin', () => {
    const origin='https://alugfacil.net.br';
    expect(allowedNavigation(origin+'/proprietario/reservas/2',origin)).toBe(true);
    for(const url of ['https://sandbox.asaas.com/i/charge','https://alugfacil.net.br.evil.test','javascript:alert(1)',origin+'/admin/dashboard','https://user@alugfacil.net.br'])expect(allowedNavigation(url,origin)).toBe(false);
  });
});
