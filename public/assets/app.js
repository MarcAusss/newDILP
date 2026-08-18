document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-file-input]').forEach((input) => {
        input.addEventListener('change', () => {
            const target = input.closest('.file-field')?.querySelector('[data-file-name]');
            if (target) {
                target.textContent = input.files?.[0]?.name || 'Choose XLSX or XLS';
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Continue?')) {
                event.preventDefault();
            }
        });
    });
});
