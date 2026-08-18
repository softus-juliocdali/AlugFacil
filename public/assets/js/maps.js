(() => {
    'use strict';

    const initializedForms = new WeakSet();
    let googleMapsPromise = null;

    const loadGoogleMaps = (apiKey) => {
        if (window.google?.maps?.importLibrary) {
            return Promise.resolve(window.google);
        }

        if (googleMapsPromise) {
            return googleMapsPromise;
        }

        googleMapsPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            const params = new URLSearchParams({
                key: apiKey,
                libraries: 'places',
                language: 'pt-BR',
                region: 'BR',
                v: 'weekly',
            });

            script.src = `https://maps.googleapis.com/maps/api/js?${params.toString()}`;
            script.async = true;
            script.defer = true;
            script.addEventListener('load', () => {
                if (window.google?.maps?.importLibrary) {
                    resolve(window.google);
                    return;
                }
                reject(new Error('Biblioteca Places indisponivel.'));
            }, { once: true });
            script.addEventListener('error', () => reject(new Error('Google Maps indisponivel.')), { once: true });
            document.head.appendChild(script);
        });

        return googleMapsPromise;
    };

    const componentValue = (components, types, property) => {
        for (const type of types) {
            const component = components.find((item) => item.types?.includes(type));
            if (component?.[property]) {
                return component[property];
            }
        }
        return '';
    };

    const initializeLocationForm = async (form, apiKey) => {
        if (initializedForms.has(form)) {
            return;
        }
        initializedForms.add(form);

        const addressInput = form.querySelector('[data-address-autocomplete]');
        const suggestionsContainer = form.querySelector('[data-address-suggestions]');
        const cityInput = form.querySelector('[data-city-input]');
        const stateInput = form.querySelector('[data-state-input]');
        const regionInput = form.querySelector('[data-region-input]');
        const latitudeInput = form.querySelector('[data-latitude-input]');
        const longitudeInput = form.querySelector('[data-longitude-input]');
        const button = form.querySelector('[data-geocode-button]');
        const feedback = form.querySelector('[data-geocode-feedback]');

        const setFeedback = (message, state = '') => {
            if (!feedback) {
                return;
            }
            feedback.textContent = message;
            feedback.dataset.state = state;
        };

        const clearSuggestions = () => {
            if (!suggestionsContainer) {
                return;
            }
            suggestionsContainer.replaceChildren();
            suggestionsContainer.hidden = true;
            addressInput?.setAttribute('aria-expanded', 'false');
        };

        if (!addressInput || !suggestionsContainer || !cityInput || !stateInput || !latitudeInput || !longitudeInput) {
            return;
        }

        stateInput.addEventListener('input', () => {
            stateInput.value = stateInput.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2);
        });

        if (!apiKey) {
            button?.setAttribute('disabled', '');
            return;
        }

        button?.setAttribute('disabled', '');

        try {
            const google = await loadGoogleMaps(apiKey);
            const { AutocompleteSessionToken, AutocompleteSuggestion } = await google.maps.importLibrary('places');
            const geocoder = new google.maps.Geocoder();
            let sessionToken = new AutocompleteSessionToken();
            let debounceTimer = 0;
            let requestSequence = 0;
            let resolvedAddress = addressInput.value.trim();

            button?.removeAttribute('disabled');
            addressInput.setAttribute('aria-expanded', 'false');

            const selectPrediction = async (prediction) => {
                clearSuggestions();
                setFeedback('Lendo os dados do endereco selecionado...', 'loading');

                try {
                    const place = prediction.toPlace();
                    await place.fetchFields({
                        fields: ['formattedAddress', 'addressComponents', 'location'],
                    });

                    const components = place.addressComponents || [];
                    const city = componentValue(components, [
                        'locality',
                        'administrative_area_level_2',
                        'postal_town',
                        'sublocality_level_1',
                    ], 'longText');
                    const state = componentValue(
                        components,
                        ['administrative_area_level_1'],
                        'shortText'
                    ).toUpperCase();
                    const formattedAddress = (place.formattedAddress || addressInput.value).trim();
                    const latitude = Number(place.location?.lat());
                    const longitude = Number(place.location?.lng());

                    if (!formattedAddress || !city || !/^[A-Z]{2}$/.test(state)
                        || !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                        setFeedback('A sugestao nao trouxe cidade, estado e coordenadas completos. Tente outra sugestao ou use o recalculo.', 'error');
                        return;
                    }

                    addressInput.value = formattedAddress;
                    cityInput.value = city;
                    stateInput.value = state;
                    latitudeInput.value = latitude.toFixed(7);
                    longitudeInput.value = longitude.toFixed(7);
                    resolvedAddress = formattedAddress;
                    sessionToken = new AutocompleteSessionToken();
                    setFeedback('Endereco selecionado: cidade, estado e coordenadas foram preenchidos.', 'success');
                } catch (_error) {
                    setFeedback('Nao foi possivel carregar os detalhes dessa sugestao. Tente outra ou use o recalculo.', 'error');
                }
            };

            const showSuggestions = (suggestions) => {
                clearSuggestions();

                suggestions.slice(0, 6).forEach((suggestion) => {
                    const prediction = suggestion.placePrediction;
                    if (!prediction) {
                        return;
                    }

                    const option = document.createElement('button');
                    option.type = 'button';
                    option.setAttribute('role', 'option');
                    option.textContent = prediction.text?.toString() || '';
                    option.addEventListener('click', () => selectPrediction(prediction));
                    suggestionsContainer.appendChild(option);
                });

                suggestionsContainer.hidden = suggestionsContainer.childElementCount === 0;
                addressInput.setAttribute('aria-expanded', suggestionsContainer.hidden ? 'false' : 'true');
            };

            addressInput.addEventListener('input', () => {
                const input = addressInput.value.trim();
                window.clearTimeout(debounceTimer);
                requestSequence += 1;

                if (input !== resolvedAddress) {
                    setFeedback('Selecione uma sugestao para atualizar toda a localizacao ou use Recalcular coordenadas.', '');
                }

                if (input.length < 3) {
                    clearSuggestions();
                    return;
                }

                const currentSequence = requestSequence;
                debounceTimer = window.setTimeout(async () => {
                    try {
                        const { suggestions } = await AutocompleteSuggestion.fetchAutocompleteSuggestions({
                            input,
                            includedRegionCodes: ['br'],
                            includedPrimaryTypes: ['street_address', 'premise', 'subpremise', 'route'],
                            language: 'pt-BR',
                            region: 'br',
                            sessionToken,
                        });

                        if (currentSequence === requestSequence) {
                            showSuggestions(suggestions);
                        }
                    } catch (_error) {
                        if (currentSequence === requestSequence) {
                            clearSuggestions();
                            setFeedback('Nao foi possivel buscar sugestoes agora. Voce pode preencher manualmente ou usar o recalculo.', 'error');
                        }
                    }
                }, 300);
            });

            addressInput.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    clearSuggestions();
                }
            });

            document.addEventListener('click', (event) => {
                if (!form.contains(event.target)) {
                    clearSuggestions();
                }
            });

            button?.addEventListener('click', () => {
                const parts = [
                    addressInput.value,
                    cityInput.value,
                    stateInput.value,
                    regionInput?.value || '',
                ].map((value) => value.trim()).filter(Boolean);

                if (!addressInput.value.trim() || !cityInput.value.trim()) {
                    setFeedback('Informe pelo menos endereco e cidade antes de recalcular.', 'error');
                    return;
                }

                button.disabled = true;
                clearSuggestions();
                setFeedback('Recalculando coordenadas...', 'loading');

                geocoder.geocode({
                    address: parts.join(', '),
                    componentRestrictions: { country: 'BR' },
                    region: 'BR',
                }, (results, status) => {
                    try {
                        const location = results?.[0]?.geometry?.location;
                        if (status !== google.maps.GeocoderStatus.OK || !location) {
                            setFeedback('Nao encontramos coordenadas para esses dados. As coordenadas anteriores foram mantidas.', 'error');
                            return;
                        }

                        const latitude = Number(location.lat());
                        const longitude = Number(location.lng());
                        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                            setFeedback('O Google retornou uma localizacao invalida. As coordenadas anteriores foram mantidas.', 'error');
                            return;
                        }

                        latitudeInput.value = latitude.toFixed(7);
                        longitudeInput.value = longitude.toFixed(7);
                        setFeedback('Coordenadas recalculadas. Confira o endereco antes de salvar.', 'success');
                    } finally {
                        button.disabled = false;
                    }
                });
            });
        } catch (_error) {
            button?.setAttribute('disabled', '');
            setFeedback('Google Maps indisponivel. O formulario continua disponivel para preenchimento manual.', 'error');
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        const loader = document.querySelector('script[data-google-maps-key]');
        const apiKey = loader?.dataset.googleMapsKey?.trim() || '';

        document.querySelectorAll('form[data-map-geocoder]').forEach((form) => {
            initializeLocationForm(form, apiKey);
        });
    });
})();
