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
