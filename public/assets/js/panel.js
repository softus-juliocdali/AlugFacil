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


    const createUploadProgressModal = () => {
        let modal = document.querySelector('[data-upload-progress-modal]');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.className = 'upload-progress-modal';
        modal.setAttribute('data-upload-progress-modal', '');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-live', 'polite');
        modal.innerHTML = `
            <div class="upload-progress-dialog">
                <div class="upload-progress-icon" aria-hidden="true">↑</div>
                <h3>Aguarde, enviando fotos</h3>
                <p data-upload-progress-status>Preparando envio...</p>
                <div class="upload-progress-track">
                    <div class="upload-progress-bar" data-upload-progress-bar style="width:0%"></div>
                </div>
                <div class="upload-progress-percent" data-upload-progress-percent>0%</div>
            </div>
        `;
        document.body.appendChild(modal);
        return modal;
    };

    const updateUploadProgress = (modal, percent, status) => {
        const safePercent = Math.max(0, Math.min(100, Math.round(percent)));
        const bar = modal.querySelector('[data-upload-progress-bar]');
        const percentLabel = modal.querySelector('[data-upload-progress-percent]');
        const statusLabel = modal.querySelector('[data-upload-progress-status]');
        if (bar) bar.style.width = `${safePercent}%`;
        if (percentLabel) percentLabel.textContent = `${safePercent}%`;
        if (status && statusLabel) statusLabel.textContent = status;
    };

    const closeUploadProgressModal = () => {
        const modal = document.querySelector('[data-upload-progress-modal]');
        if (!modal) return;
        modal.classList.add('is-closing');
        window.setTimeout(() => modal.remove(), 180);
    };

    const showUploadToast = (type, message) => {
        const old = document.querySelector('[data-upload-result-flash]');
        old?.remove();

        const flash = document.createElement('div');
        flash.className = `flash flash-${type}`;
        flash.setAttribute('role', type === 'error' ? 'alert' : 'status');
        flash.setAttribute('data-upload-result-flash', '');
        flash.innerHTML = `
            <span></span>
            <button type="button" aria-label="Fechar aviso">&times;</button>
        `;
        flash.querySelector('span').textContent = message;
        flash.querySelector('button').addEventListener('click', () => {
            flash.classList.add('is-hiding');
            window.setTimeout(() => flash.remove(), 250);
        });
        document.body.appendChild(flash);
        window.setTimeout(() => {
            if (!flash.isConnected) return;
            flash.classList.add('is-hiding');
            window.setTimeout(() => flash.remove(), 250);
        }, 4000);
    };

    const getUploadResultFromResponse = (html) => {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const success = parsed.querySelector('.flash-success');
        const error = parsed.querySelector('.flash-error');
        if (success) {
            return { type: 'success', message: success.querySelector('span')?.textContent?.trim() || 'Fotos enviadas com sucesso.' };
        }
        if (error) {
            return { type: 'error', message: error.querySelector('span')?.textContent?.trim() || 'Não foi possível enviar as fotos agora.' };
        }
        return null;
    };

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


    uploadForm?.addEventListener('submit', (event) => {
        if (!photoInput || !uploadForm) return;

        const files = Array.from(photoInput.files || []);
        if (!files.length) return;

        event.preventDefault();

        const submitButton = uploadForm.querySelector('button[type="submit"]');
        const modal = createUploadProgressModal();
        const formData = new FormData(uploadForm);
        const xhr = new XMLHttpRequest();

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent || 'Enviar fotos';
            submitButton.textContent = 'Enviando...';
        }

        updateUploadProgress(modal, 0, 'Preparando envio...');

        xhr.open('POST', uploadForm.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', (progressEvent) => {
            if (!progressEvent.lengthComputable) {
                updateUploadProgress(modal, 0, 'Enviando fotos...');
                return;
            }

            const percent = (progressEvent.loaded / progressEvent.total) * 100;
            updateUploadProgress(
                modal,
                percent,
                percent >= 100 ? 'Fotos enviadas. Processando...' : 'Enviando fotos...'
            );
        });

        xhr.addEventListener('load', () => {
            const result = getUploadResultFromResponse(xhr.responseText);

            if (result) {
                updateUploadProgress(modal, 100, result.type === 'success' ? 'Concluído.' : 'Não foi possível concluir o envio.');
                closeUploadProgressModal();
                showUploadToast(result.type, result.message);

                if (result.type === 'success') {
                    window.setTimeout(() => window.location.reload(), 900);
                } else if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = submitButton.dataset.originalText || 'Enviar fotos';
                }
                return;
            }

            closeUploadProgressModal();
            showUploadToast('error', 'Não foi possível concluir o envio das fotos.');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText || 'Enviar fotos';
            }
        });

        xhr.addEventListener('error', () => {
            closeUploadProgressModal();
            showUploadToast('error', 'Não foi possível enviar as fotos. Verifique sua conexão e tente novamente.');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText || 'Enviar fotos';
            }
        });

        xhr.addEventListener('abort', () => {
            closeUploadProgressModal();
            showUploadToast('error', 'O envio das fotos foi interrompido.');
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = submitButton.dataset.originalText || 'Enviar fotos';
            }
        });

        xhr.send(formData);
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
