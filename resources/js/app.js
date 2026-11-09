const openSiteMenus = () => document.querySelectorAll('details[data-site-menu][open]');

document.addEventListener('click', (event) => {
    openSiteMenus().forEach((menu) => {
        if (!menu.contains(event.target)) {
            menu.removeAttribute('open');
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    openSiteMenus().forEach((menu) => {
        menu.removeAttribute('open');
        menu.querySelector('summary')?.focus();
    });
});
