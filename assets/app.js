/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap';
import './styles/app.css';

// Shows the chosen photo before it is sent.
document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.dataset.preview === undefined || !input.files?.[0]) {
        return;
    }

    const preview = document.getElementById(input.dataset.preview);
    preview.src = URL.createObjectURL(input.files[0]);
    preview.closest('.d-none')?.classList.remove('d-none');
});

// Recognition takes a few seconds: show it and prevent a second submission.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.loading === undefined) {
        return;
    }

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.querySelector('.spinner-border')?.classList.remove('d-none');
    button.querySelector('[data-label]').textContent = form.dataset.loading;
});
