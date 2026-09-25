const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
export function money(cents: number) {
  if (!Number.isSafeInteger(cents) || cents < 0) throw new RangeError('Invalid cents');
  return brl.format(cents / 100);
}
export const propertyType = { chacara: 'Chácara', sitio: 'Sítio', area_lazer: 'Área de lazer' };
export function hours(start: string | null, end: string | null) {
  return start && end ? `${start} às ${end}` : start ? `A partir de ${start}` : end ? `Até ${end}` : 'Não informado';
}
