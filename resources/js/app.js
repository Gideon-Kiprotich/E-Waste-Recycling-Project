const errorSummary = document.querySelector('[data-error-summary]');

if (errorSummary) {
    errorSummary.focus();
}

document.querySelector('[data-print-label]')?.addEventListener('click', () => window.print());
