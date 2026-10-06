const errorSummary = document.querySelector('[data-error-summary]');

if (errorSummary) {
    errorSummary.focus();
    errorSummary.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        if (link) {
            const field = document.getElementById(link.getAttribute('href').slice(1));
            const details = field?.closest('details');
            if (details) details.open = true;
            field?.focus();
        }
    });
}

document.querySelector('[data-print-label]')?.addEventListener('click', () => window.print());

const pickupLocation = document.querySelector('[data-pickup-location]');
if (pickupLocation) {
    import('./pickup-location.js').then(({ enhancePickupLocation }) => enhancePickupLocation(pickupLocation)).catch(() => {
        pickupLocation.querySelector('[data-manual-location]').open = true;
        pickupLocation.querySelector('[data-location-message]').textContent = 'Map enhancement is unavailable. Use Advanced location entry, then confirm the point.';
    });
}
