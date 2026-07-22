document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-flash]').forEach((flash) => {
        const close = () => {
            flash.classList.add('is-hiding');
            window.setTimeout(() => flash.remove(), 250);
        };

        flash.querySelector('[data-flash-close]')?.addEventListener('click', close);
        window.setTimeout(close, 3000);
    });

    const toggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');

    const closeMobile = () => {
        sidebar?.classList.remove('is-open');
        overlay?.classList.remove('is-visible');
    };

    toggle?.addEventListener('click', () => {
        if (window.innerWidth <= 800) {
            sidebar?.classList.toggle('is-open');
            overlay?.classList.toggle('is-visible');
            return;
        }
        document.body.classList.toggle('sidebar-collapsed');
    });

    overlay?.addEventListener('click', closeMobile);
    window.addEventListener('resize', () => {
        if (window.innerWidth > 800) closeMobile();
    });

    const cpfInputs = document.querySelectorAll('[data-cpf-mask]');
    const maskCpf = (value) => {
        const digits = value.replace(/\D/g, '').slice(0, 11);

        if (digits.length <= 3) return digits;
        if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`;
        if (digits.length <= 9) return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6)}`;

        return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`;
    };

    cpfInputs.forEach((input) => {
        input.value = maskCpf(input.value);
        input.addEventListener('input', () => {
            input.value = maskCpf(input.value);
        });
    });

    const photoInput = document.querySelector('[data-photo-input]');
    const preview = document.querySelector('[data-photo-preview]');
    const uploadForm = document.querySelector('[data-upload-form]');
    const feedback = document.querySelector('[data-photo-feedback]');

    photoInput?.addEventListener('change', () => {
        if (!preview) return;
        preview.innerHTML = '';
        if (feedback) {
            feedback.hidden = true;
            feedback.textContent = '';
        }

        const files = Array.from(photoInput.files || []);
        const maxFileBytes = Number(uploadForm?.dataset.maxFileBytes || 5242880);
        const maxPostBytes = Number(uploadForm?.dataset.maxPostBytes || 0);
        const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
        const oversized = files.find((file) => file.size > maxFileBytes);

        if (oversized || (maxPostBytes > 0 && totalBytes > maxPostBytes * 0.9)) {
            photoInput.value = '';
            const maxFileMb = Math.round((maxFileBytes / 1024 / 1024) * 10) / 10;
            const message = oversized
                ? `Cada foto deve ter no maximo ${maxFileMb}MB.`
                : 'O lote selecionado e grande demais para o servidor. Envie menos fotos por vez.';

            if (feedback) {
                feedback.textContent = message;
                feedback.hidden = false;
            } else {
                alert(message);
            }

            return;
        }

        files.forEach((file) => {
            if (!file.type.startsWith('image/')) return;

            const img = document.createElement('img');
            img.alt = file.name;
            img.src = URL.createObjectURL(file);
            img.addEventListener('load', () => URL.revokeObjectURL(img.src), { once: true });
            preview.appendChild(img);
        });
    });

    const availabilityProperty = document.querySelector('[data-availability-property]');
    availabilityProperty?.addEventListener('change', () => {
        const target = availabilityProperty.value;
        if (target) window.location.href = target;
    });

    const billingPeriod = document.querySelector('[data-billing-period]');
    const toggleBillingCustom = () => {
        const form = billingPeriod?.closest('form');
        if (!form) return;

        form.querySelectorAll('input[type="date"]').forEach((input) => {
            input.disabled = billingPeriod.value !== 'personalizado';
        });
    };

    billingPeriod?.addEventListener('change', toggleBillingCustom);
    toggleBillingCustom();
});
