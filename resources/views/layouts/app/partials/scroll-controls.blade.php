<style>
    .tesda-scroll-controls {
        position: fixed;
        right: max(1rem, env(safe-area-inset-right));
        bottom: max(1.25rem, env(safe-area-inset-bottom));
        z-index: 80;
        display: flex;
        flex-direction: column;
        gap: .625rem;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateY(.5rem);
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
    }

    .tesda-scroll-controls[data-visible="true"] {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0);
    }

    .tesda-scroll-controls [hidden] { display: none !important; }

    .tesda-scroll-control {
        display: inline-flex;
        width: 2.875rem;
        height: 2.875rem;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, .28);
        border-radius: 9999px;
        background: #102f68;
        color: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .22);
        cursor: pointer;
        transition: background-color .15s ease, color .15s ease, transform .15s ease;
    }

    .tesda-scroll-control:hover {
        background: #0e7490;
        color: #fff;
        transform: translateY(-1px);
    }

    .tesda-scroll-control:focus-visible { outline: 3px solid #fbbf24; outline-offset: 3px; }

    @media (prefers-reduced-motion: reduce) {
        .tesda-scroll-controls, .tesda-scroll-control { transition: none; }
    }
</style>

<div class="tesda-scroll-controls" data-tesda-scroll-controls data-visible="false" aria-label="Page scroll controls">
    <button type="button" class="tesda-scroll-control" data-tesda-scroll-up hidden aria-label="Scroll up one screen" title="Scroll up">
        <flux:icon icon="arrow-up" class="size-5" />
    </button>
    <button type="button" class="tesda-scroll-control" data-tesda-scroll-down hidden aria-label="Scroll down one screen" title="Scroll down">
        <flux:icon icon="arrow-down" class="size-5" />
    </button>
</div>

<script>
    (() => {
        const globalKey = '__tesdaConnectScrollControlsPageCornerV3';

        const update = () => {
            const controls = document.querySelector('[data-tesda-scroll-controls]');
            if (!controls) return;

            const scrollingElement = document.scrollingElement || document.documentElement;
            const scrollTop = window.scrollY || scrollingElement.scrollTop || 0;
            const maxScroll = Math.max(0, scrollingElement.scrollHeight - window.innerHeight);
            const hasScrolled = scrollTop > 80;
            const canScrollDown = maxScroll - scrollTop > 80;

            controls.dataset.visible = hasScrolled ? 'true' : 'false';
            const up = controls.querySelector('[data-tesda-scroll-up]');
            const down = controls.querySelector('[data-tesda-scroll-down]');
            if (up) up.hidden = !hasScrolled;
            if (down) down.hidden = !hasScrolled || !canScrollDown;
        };

        if (!window[globalKey]) {
            let frame = null;
            const scheduleUpdate = () => {
                if (frame !== null) window.cancelAnimationFrame(frame);
                frame = window.requestAnimationFrame(() => {
                    frame = null;
                    update();
                });
            };

            window.addEventListener('scroll', scheduleUpdate, { passive: true });
            window.addEventListener('resize', scheduleUpdate, { passive: true });
            document.addEventListener('livewire:navigated', scheduleUpdate);
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;
                const up = event.target.closest('[data-tesda-scroll-up]');
                const down = event.target.closest('[data-tesda-scroll-down]');
                if (!up && !down) return;

                const distance = Math.max(320, Math.round(window.innerHeight * 0.75));
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollBy({
                    top: up ? -distance : distance,
                    behavior: reducedMotion ? 'auto' : 'smooth',
                });
            });
            window[globalKey] = true;
        }

        update();
        document.addEventListener('livewire:navigated', update);
    })();
</script>
