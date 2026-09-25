(() => {
  const form = document.querySelector('[data-owner-registration]');
  if (!form) return;
  const person = form.elements.tipo_pessoa;
  const updatePerson = () => form.querySelectorAll('[data-person]').forEach(label => {
    const active = label.dataset.person === person.value;
    label.hidden = !active;
    const input = label.querySelector('input,select');
    input.disabled = !active;
    input.required = active;
  });
  person.addEventListener('change', updatePerson);
  updatePerson();
  const cep = form.querySelector('[data-owner-cep]');
  const status = form.querySelector('[data-cep-status]');
  const fields = { endereco: 'logradouro', bairro: 'bairro', cidade: 'localidade', estado: 'uf' };
  let request;
  let sequence = 0;
  cep.addEventListener('input', () => { sequence++; request?.abort(); });
  cep.addEventListener('blur', async () => {
    const code = cep.value.replace(/\D/g, '');
    if (code.length !== 8) { status.textContent = 'Informe um CEP com 8 dígitos. O endereço pode ser preenchido manualmente.'; return; }
    request?.abort();
    const controller = new AbortController();
    request = controller;
    const current = ++sequence;
    const before = Object.fromEntries(Object.keys(fields).map(k => [k, form.elements[k].value]));
    const timeout = setTimeout(() => controller.abort(), 5000);
    status.textContent = 'Buscando endereço…';
    try {
      const response = await fetch(`https://viacep.com.br/ws/${code}/json/`, { signal: controller.signal, credentials: 'omit', referrerPolicy: 'no-referrer' });
      if (!response.ok) throw new Error('lookup');
      const result = await response.json();
      if (result.erro) throw new Error('not_found');
      if (current !== sequence) return;
      for (const [field, key] of Object.entries(fields)) {
        // Preserve manual edits made while the request was in flight.
        if (form.elements[field].value === before[field] && typeof result[key] === 'string') form.elements[field].value = result[key];
      }
      status.textContent = 'Endereço encontrado. Confira os dados e informe o número.';
    } catch {
      if (current === sequence) status.textContent = 'Não foi possível consultar o CEP. Preencha o endereço manualmente para continuar.';
    } finally { clearTimeout(timeout); }
  });
})();
