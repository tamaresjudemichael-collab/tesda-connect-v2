<style>
    .tesda-scroll-controls {
        position: fixed;
        right: max(1rem, env(safe-area-inset-right));
        bottom: max(1rem, env(safe-area-inset-bottom));
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        width: auto;
        padding: 0;
        box-sizing: border-box;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transform: translateY(.25rem);
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
    }

    .tesda-scroll-controls[data-visible="true"] {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0);
    }

    .tesda-scroll-controls [hidden] {
        display: none !important;
    }

    .tesda-scroll-control {
        display: inline-flex;
        flex: 0 0 auto;
        width: 2.75rem;
        height: 2.75rem;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, .28);
        border-radius: 9999px;
        background: #102f68;
        color: #fff;
        box-shadow: 0 4px 12px rgba(15, 23, 42, .22);
        cursor: pointer;
        transition: background-color .15s ease, color .15s ease, transform .15s ease;
    }

    .tesda-scroll-control:hover {
        background: #0e7490;
        color: #fff;
        transform: translateY(-1px);
    }

    .tesda-scroll-control:focus-visible {
        outline: 3px solid #fbbf24;
        outline-offset: 3px;
    }

    @media (max-width: 640px) {
        .tesda-scroll-controls {
            right: max(.75rem, env(safe-area-inset-right));
            bottom: max(.75rem, env(safe-area-inset-bottom));
        }

        .tesda-scroll-control {
            width: 2.5rem;
            height: 2.5rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .tesda-scroll-controls,
        .tesda-scroll-control {
            transition: none;
        }
    }
</style>

<div class="tesda-scroll-controls" data-tesda-scroll-controls data-visible="false" aria-label="Page scroll control">
    <button type="button" class="tesda-scroll-control" data-tesda-scroll-toggle data-direction="down"
        aria-label="Scroll to page bottom" title="Scroll to bottom">
        <span data-tesda-scroll-icon-down>
            <flux:icon icon="arrow-down" class="size-5" />
        </span>
        <span data-tesda-scroll-icon-up hidden>
            <flux:icon icon="arrow-up" class="size-5" />
        </span>
    </button>
</div>

<script>
    (() => {
        const globalKey = '__tesdaConnectScrollControlsRightPageV1';

        const getScrollState = () => {
            const scrollingElement = document.scrollingElement || document.documentElement;
            const scrollTop = window.scrollY || scrollingElement.scrollTop || 0;
            const maxScroll = Math.max(0, scrollingElement.scrollHeight - window.innerHeight);
            const threshold = 80;
            const canScroll = maxScroll > threshold;
            const atBottom = canScroll && (maxScroll - scrollTop) <= threshold;
            return { canScroll, atBottom, maxScroll };
        };

        const update = () => {
            const controls = document.querySelector('[data-tesda-scroll-controls]');
            if (!controls) return;

            const button = controls.querySelector('[data-tesda-scroll-toggle]');
            const downIcon = controls.querySelector('[data-tesda-scroll-icon-down]');
            const upIcon = controls.querySelector('[data-tesda-scroll-icon-up]');
            if (!button) return;

            const state = getScrollState();
            controls.dataset.visible = state.canScroll ? 'true' : 'false';
            button.dataset.direction = state.atBottom ? 'up' : 'down';
            button.setAttribute('aria-label', state.atBottom ? 'Scroll to page top' : 'Scroll to page bottom');
            button.title = state.atBottom ? 'Scroll to top' : 'Scroll to bottom';
            if (downIcon) downIcon.hidden = state.atBottom;
            if (upIcon) upIcon.hidden = !state.atBottom;
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
                const button = event.target.closest('[data-tesda-scroll-toggle]');
                if (!button) return;

                const state = getScrollState();
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({ top: state.atBottom ? 0 : state.maxScroll, behavior: reducedMotion ? 'auto' : 'smooth' });
            });

            window[globalKey] = true;
        }

        update();
        document.addEventListener('livewire:navigated', update);
    })();
</script>
