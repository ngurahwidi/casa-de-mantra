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

document.querySelectorAll('[data-review-slider]').forEach((section) => {
    const rail = section.querySelector('[data-review-rail]');
    const controls = section.querySelector('[data-review-controls]');

    if (!(rail instanceof HTMLElement)) {
        return;
    }

    const firstCard = rail.querySelector('[data-review-card]');
    const scrollStep = () => {
        const gap = Number.parseFloat(window.getComputedStyle(rail).columnGap) || 0;

        return (firstCard?.getBoundingClientRect().width ?? rail.clientWidth) + gap;
    };

    section.querySelectorAll('[data-review-scroll]').forEach((button) => {
        button.addEventListener('click', () => {
            const direction = button.dataset.reviewScroll === 'next' ? 1 : -1;
            const step = scrollStep();
            const visibleCards = Math.max(1, Math.round(rail.clientWidth / step));

            rail.scrollBy({ left: direction * step * visibleCards, behavior: 'smooth' });
        });
    });

    const syncControls = () => {
        const maxScrollLeft = rail.scrollWidth - rail.clientWidth;
        const hint = section.querySelector('[data-review-hint]');

        if (controls instanceof HTMLElement) {
            controls.style.display = maxScrollLeft > 1 ? '' : 'none';
        }

        if (hint instanceof HTMLElement) {
            hint.style.display = maxScrollLeft > 1 ? '' : 'none';
        }

        section.querySelector('[data-review-scroll="prev"]')?.toggleAttribute('disabled', rail.scrollLeft <= 1);
        section.querySelector('[data-review-scroll="next"]')?.toggleAttribute('disabled', rail.scrollLeft >= maxScrollLeft - 1);
    };

    rail.addEventListener('scroll', syncControls, { passive: true });
    window.addEventListener('resize', syncControls);
    syncControls();
});
