document.addEventListener('DOMContentLoaded', () => {
    const script = document.querySelector('script[data-google-maps-key]');
    const apiKey = script?.dataset.googleMapsKey || '';

    document.querySelectorAll('[data-map-geocoder]').forEach((container) => {
        const button = container.querySelector('[data-geocode-button]');
        const feedback = container.querySelector('[data-geocode-feedback]');
        const latitudeInput = container.querySelector('[data-latitude-input]');
        const longitudeInput = container.querySelector('[data-longitude-input]');
        const form = container.closest('form');

        const setFeedback = (message, state = '') => {
            if (!feedback) return;
            feedback.textContent = message;
            feedback.dataset.state = state;
        };

        if (!apiKey) {
            button?.setAttribute('disabled', '');
            return;
        }

        button?.addEventListener('click', async () => {
            const parts = ['endereco', 'cidade', 'regiao']
                .map((name) => form?.querySelector(`[name="${name}"]`)?.value.trim())
                .filter(Boolean);

            if (parts.length < 2) {
                setFeedback('Informe pelo menos endereco e cidade antes de buscar.', 'error');
                return;
            }

            button.disabled = true;
            setFeedback('Buscando coordenadas...', 'loading');

            try {
                const params = new URLSearchParams({
                    address: parts.join(', '),
                    key: apiKey,
                    region: 'br',
                    language: 'pt-BR',
                });
                const response = await fetch(`https://maps.googleapis.com/maps/api/geocode/json?${params.toString()}`);
                const payload = await response.json();

                if (!response.ok || payload.status !== 'OK' || !payload.results?.length) {
                    setFeedback('Nao encontramos coordenadas para esse endereco. Revise os dados ou preencha manualmente.', 'error');
                    return;
                }

                const location = payload.results[0].geometry?.location;
                if (!location) {
                    setFeedback('Nao foi possivel ler a localizacao retornada pelo Google.', 'error');
                    return;
                }

                latitudeInput.value = Number(location.lat).toFixed(7);
                longitudeInput.value = Number(location.lng).toFixed(7);
                setFeedback('Coordenadas preenchidas. Confira se o ponto esta correto antes de salvar.', 'success');
            } catch (_) {
                setFeedback('Nao foi possivel consultar o Google Maps agora. Tente novamente em instantes.', 'error');
            } finally {
                button.disabled = false;
            }
        });
    });
});
