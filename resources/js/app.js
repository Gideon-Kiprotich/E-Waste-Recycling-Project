const errorSummary = document.querySelector('[data-error-summary]');

if (errorSummary) {
    errorSummary.focus();
    errorSummary.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (link) document.getElementById(link.getAttribute('href').slice(1))?.focus();
    });
}

document.querySelector('[data-print-label]')?.addEventListener('click', () => window.print());
