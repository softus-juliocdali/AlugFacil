document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-flash]').forEach((flash) => {
        const close = () => {
            flash.classList.add('is-hiding');
            window.setTimeout(() => flash.remove(), 250);
        };

        flash.querySelector('[data-flash-close]')?.addEventListener('click', close);
        window.setTimeout(close, 3000);
    });

    const menuButton = document.querySelector('[data-menu-toggle]');
    const mobileMenu = document.querySelector('[data-mobile-menu]');

    menuButton?.addEventListener('click', () => {
        const open = mobileMenu.classList.toggle('is-open');
        menuButton.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('menu-open', open);
    });

    mobileMenu?.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            mobileMenu.classList.remove('is-open');
            menuButton?.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('menu-open');
        });
    });

    document.querySelectorAll('.heart-button:not([type="submit"])').forEach((button) => {
        button.addEventListener('click', () => {
            const favorite = button.classList.toggle('is-favorite');
            button.setAttribute('aria-pressed', String(favorite));
        });
    });

    const filterButton = document.querySelector('[data-filter-toggle]');
    const filters = document.querySelector('[data-filters]');
    filterButton?.addEventListener('click', () => {
        filters?.classList.add('is-open');
        document.body.classList.add('filters-open');
    });
    filters?.addEventListener('click', (event) => {
        if (window.innerWidth <= 980 && event.target === filters) {
            filters.classList.remove('is-open');
            document.body.classList.remove('filters-open');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            filters?.classList.remove('is-open');
            document.body.classList.remove('filters-open', 'menu-open');
            mobileMenu?.classList.remove('is-open');
        }
    });

    document.querySelectorAll('[data-auto-submit]').forEach((field) => {
        field.addEventListener('change', () => field.form?.submit());
    });

    document.querySelectorAll('[data-details-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const details = document.getElementById(button.getAttribute('aria-controls'));
            if (!details) return;

            const expanded = button.getAttribute('aria-expanded') === 'true';
            details.hidden = expanded;
            button.setAttribute('aria-expanded', String(!expanded));
            button.textContent = expanded ? 'Ver Detalhes' : 'Ocultar Detalhes';
        });
    });

    const checkIn = document.querySelector('input[name="data_inicio"]');
    document.querySelectorAll('input[name="data_fim"]').forEach((checkOut) => {
        checkIn?.addEventListener('change', () => {
            if (!checkIn.value) return;
            const nextDay = new Date(`${checkIn.value}T12:00:00`);
            nextDay.setDate(nextDay.getDate() + 1);
            checkOut.min = nextDay.toISOString().slice(0, 10);
            if (checkOut.value && checkOut.value < checkOut.min) checkOut.value = '';
        });
    });

    document.querySelectorAll('[data-reservation-calendar]').forEach((calendar) => {
        const rangeInput = calendar.querySelector('[data-reservation-range]');
        const enhanced = calendar.querySelector('[data-reservation-enhanced]');
        const nativeFields = calendar.querySelector('[data-reservation-native]');
        const unavailableData = calendar.querySelector('[data-reservation-unavailable]');
        const startInput = nativeFields?.querySelector('input[name="data_inicio"]');
        const endInput = nativeFields?.querySelector('input[name="data_fim"]');
        const status = calendar.querySelector('[data-reservation-calendar-status]');
        const clearButton = calendar.querySelector('[data-reservation-clear]');
        const locked = calendar.dataset.locked === 'true';

        if (!rangeInput || !enhanced || !nativeFields || !startInput || !endInput || typeof window.flatpickr !== 'function') return;

        let unavailableDates = [];
        try {
            const parsedUnavailableDates = JSON.parse(unavailableData?.textContent || '[]');
            unavailableDates = Array.isArray(parsedUnavailableDates) ? parsedUnavailableDates : [];
        } catch (_) {
            unavailableDates = [];
        }

        const parseLocalDate = (value) => {
            const parts = String(value || '').split('-').map(Number);
            if (parts.length !== 3 || parts.some((part) => !Number.isInteger(part))) return null;
            const date = new Date(parts[0], parts[1] - 1, parts[2], 12, 0, 0, 0);
            if (
                Number.isNaN(date.getTime())
                || date.getFullYear() !== parts[0]
                || date.getMonth() !== parts[1] - 1
                || date.getDate() !== parts[2]
            ) return null;
            return date;
        };
        const dateKey = (date) => [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
        const displayDate = (date) => new Intl.DateTimeFormat('pt-BR').format(date);
        const unavailable = new Set(unavailableDates.filter((date) => /^\d{4}-\d{2}-\d{2}$/.test(date)));
        const minDate = parseLocalDate(calendar.dataset.minDate);
        const maxDate = parseLocalDate(calendar.dataset.maxDate);
        let checkoutBoundary = null;

        const firstUnavailableAfter = (start) => {
            const startKey = dateKey(start);
            const next = [...unavailable].filter((key) => key > startKey).sort()[0];
            return next ? parseLocalDate(next) : null;
        };
        const disabledDates = () => [...unavailable]
            .filter((key) => key !== checkoutBoundary)
            .map(parseLocalDate)
            .filter(Boolean);
        const rangeIsValid = (start, end) => {
            if (!start || !end || end <= start || unavailable.has(dateKey(start))) return false;
            const startKey = dateKey(start);
            const endKey = dateKey(end);
            return ![...unavailable].some((key) => key >= startKey && key < endKey);
        };
        const nightsBetween = (start, end) => Math.round((
            Date.UTC(end.getFullYear(), end.getMonth(), end.getDate())
            - Date.UTC(start.getFullYear(), start.getMonth(), start.getDate())
        ) / 86400000);

        const initialStart = parseLocalDate(startInput.value);
        const initialEnd = parseLocalDate(endInput.value);
        const initialBoundary = initialStart ? firstUnavailableAfter(initialStart) : null;
        checkoutBoundary = initialBoundary ? dateKey(initialBoundary) : null;
        const initialDates = locked && initialStart && initialEnd && initialEnd > initialStart
            ? [initialStart, initialEnd]
            : rangeIsValid(initialStart, initialEnd)
            ? [initialStart, initialEnd]
            : (initialStart && !unavailable.has(dateKey(initialStart)) ? [initialStart] : []);

        const picker = window.flatpickr(rangeInput, {
            mode: 'range',
            dateFormat: 'd/m/Y',
            defaultDate: initialDates,
            minDate,
            maxDate: locked ? maxDate : (checkoutBoundary ? parseLocalDate(checkoutBoundary) : maxDate),
            disable: locked ? [] : disabledDates(),
            disableMobile: true,
            clickOpens: !locked,
            allowInput: false,
            locale: window.flatpickr.l10ns?.pt || 'default',
            monthSelectorType: 'static',
            ariaDateFormat: 'j F Y',
            onReady: (_selectedDates, _dateString, instance) => {
                instance.calendarContainer.classList.add('reservation-date-picker');
            },
            onDayCreate: (_selectedDates, _dateString, _instance, dayElement) => {
                const key = dateKey(dayElement.dateObj);
                if (!unavailable.has(key)) return;
                if (key === checkoutBoundary) {
                    dayElement.classList.add('is-checkout-boundary');
                    dayElement.title = 'Disponível somente como data de saída';
                } else {
                    dayElement.classList.add('is-reservation-unavailable');
                    dayElement.title = 'Data indisponível';
                }
            },
            onChange: (selectedDates, _dateString, instance) => {
                if (selectedDates.length === 0) {
                    checkoutBoundary = null;
                    startInput.value = '';
                    endInput.value = '';
                    instance.set('disable', disabledDates());
                    instance.set('maxDate', maxDate);
                    if (status) status.textContent = 'Selecione a entrada e depois a saída. A saída não conta como diária.';
                    return;
                }

                if (selectedDates.length === 1) {
                    const start = selectedDates[0];
                    const boundary = firstUnavailableAfter(start);
                    checkoutBoundary = boundary ? dateKey(boundary) : null;
                    startInput.value = dateKey(start);
                    endInput.value = '';
                    instance.set('disable', disabledDates());
                    instance.set('maxDate', boundary || maxDate);
                    if (status) status.textContent = boundary
                        ? `Escolha a saída até ${displayDate(boundary)}. Essa data pode ser usada somente como saída.`
                        : 'Agora escolha a data de saída.';
                    return;
                }

                const [start, end] = selectedDates;
                if (!rangeIsValid(start, end)) {
                    instance.clear();
                    if (status) status.textContent = 'O período atravessa uma data indisponível. Selecione outro intervalo.';
                    return;
                }

                startInput.value = dateKey(start);
                endInput.value = dateKey(end);
                startInput.dispatchEvent(new Event('change', { bubbles: true }));
                endInput.dispatchEvent(new Event('change', { bubbles: true }));
                const nights = nightsBetween(start, end);
                if (status) status.textContent = `${nights} ${nights === 1 ? 'diária' : 'diárias'}: entrada em ${displayDate(start)} e saída em ${displayDate(end)}.`;
            },
        });

        startInput.required = false;
        endInput.required = false;
        nativeFields.hidden = true;
        enhanced.hidden = false;

        if (locked && initialStart && initialEnd) {
            if (status) status.textContent = `Período protegido pela cotação: entrada em ${displayDate(initialStart)} e saída em ${displayDate(initialEnd)}.`;
        } else if (initialDates.length === 2 && status) {
            const nights = nightsBetween(initialDates[0], initialDates[1]);
            status.textContent = `${nights} ${nights === 1 ? 'diária' : 'diárias'}: entrada em ${displayDate(initialDates[0])} e saída em ${displayDate(initialDates[1])}.`;
        }

        clearButton?.addEventListener('click', () => picker.clear());
        calendar.closest('form')?.addEventListener('submit', (event) => {
            if (startInput.value && endInput.value) return;
            event.preventDefault();
            if (status) status.textContent = 'Selecione as datas de entrada e saída antes de continuar.';
            picker.open();
        });
    });

    const galleryData = document.querySelector('[data-gallery-images]');
    const galleryModal = document.querySelector('[data-gallery-modal]');
    const galleryImage = document.querySelector('[data-gallery-modal-image]');
    let galleryImages = [];
    let galleryIndex = 0;

    if (galleryData) {
        try { galleryImages = JSON.parse(galleryData.textContent); } catch (_) { galleryImages = []; }
    }

    const showGalleryImage = () => {
        if (galleryImage && galleryImages.length) galleryImage.src = galleryImages[galleryIndex % galleryImages.length];
    };
    document.querySelectorAll('[data-gallery-open]').forEach((button) => {
        button.addEventListener('click', () => {
            galleryIndex = Number(button.dataset.galleryOpen) % Math.max(galleryImages.length, 1);
            showGalleryImage();
            galleryModal?.removeAttribute('hidden');
            document.body.style.overflow = 'hidden';
        });
    });
    document.querySelector('[data-gallery-close]')?.addEventListener('click', () => {
        galleryModal?.setAttribute('hidden', '');
        document.body.style.overflow = '';
    });
    document.querySelector('[data-gallery-prev]')?.addEventListener('click', () => {
        galleryIndex = (galleryIndex - 1 + galleryImages.length) % galleryImages.length;
        showGalleryImage();
    });
    document.querySelector('[data-gallery-next]')?.addEventListener('click', () => {
        galleryIndex = (galleryIndex + 1) % galleryImages.length;
        showGalleryImage();
    });

    const calendarMonths = [...document.querySelectorAll('[data-calendar-month]')];
    let calendarStart = 0;
    const renderCalendars = () => {
        const mobile = window.innerWidth <= 680;
        calendarMonths.forEach((month, index) => {
            month.hidden = index < calendarStart || index >= calendarStart + (mobile ? 1 : 2);
        });
    };
    document.querySelector('[data-calendar-prev]')?.addEventListener('click', () => {
        calendarStart = Math.max(0, calendarStart - 1);
        renderCalendars();
    });
    document.querySelector('[data-calendar-next]')?.addEventListener('click', () => {
        calendarStart = Math.min(Math.max(0, calendarMonths.length - (window.innerWidth <= 680 ? 1 : 2)), calendarStart + 1);
        renderCalendars();
    });
    if (calendarMonths.length) {
        renderCalendars();
        window.addEventListener('resize', renderCalendars);
    }

    const reviewModal = document.querySelector('[data-review-modal]');
    document.querySelector('[data-review-open]')?.addEventListener('click', () => {
        reviewModal?.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    });
    document.querySelector('[data-review-close]')?.addEventListener('click', () => {
        reviewModal?.setAttribute('hidden', '');
        document.body.style.overflow = '';
    });

    document.querySelectorAll('[data-reservation-form]').forEach((form) => {
        const start = form.querySelector('[data-reservation-start]');
        const end = form.querySelector('[data-reservation-end]');
        const rate = Number.parseFloat(form.querySelector('[data-daily-rate]')?.value || '0');
        const nightsOutput = form.querySelector('[data-reservation-nights]');
        const totalOutput = form.querySelector('[data-reservation-total]');
        const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

        const calculate = () => {
            if (start?.value && end) {
                const nextDay = new Date(`${start.value}T12:00:00`);
                nextDay.setDate(nextDay.getDate() + 1);
                end.min = nextDay.toISOString().slice(0, 10);
                if (end.value && end.value < end.min) end.value = '';
            }

            const startDate = start?.value ? new Date(`${start.value}T12:00:00`) : null;
            const endDate = end?.value ? new Date(`${end.value}T12:00:00`) : null;
            let nights = 0;

            if (startDate && endDate && endDate > startDate) {
                nights = Math.round((endDate - startDate) / 86400000);
            }

            if (nightsOutput) nightsOutput.textContent = String(nights);
            if (totalOutput) totalOutput.textContent = currency.format(nights * rate);
        };

        start?.addEventListener('change', calculate);
        end?.addEventListener('change', calculate);
        calculate();
    });
});
