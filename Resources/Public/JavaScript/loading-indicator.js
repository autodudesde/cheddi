export function loadingIndicator(text) {
    const row = document.createElement('li');
    row.className = 'cheddi__open-items-loading';
    row.dataset.cheddiOpenItemsLoading = '';
    row.setAttribute('role', 'status');

    const dots = document.createElement('span');
    dots.className = 'cheddi__thinking-dots';
    dots.setAttribute('aria-hidden', 'true');
    dots.append(document.createElement('span'), document.createElement('span'), document.createElement('span'));

    const label = document.createElement('span');
    label.className = 'cheddi__open-items-loading-text';
    label.textContent = text;

    row.append(dots, label);
    return row;
}
