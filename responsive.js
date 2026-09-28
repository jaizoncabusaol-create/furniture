(function () {
    const root = document.documentElement;
    const coarseQuery = window.matchMedia('(any-pointer: coarse)');

    function updateDeviceLayout() {
        const viewport = window.visualViewport;
        const viewportWidth = viewport ? viewport.width : window.innerWidth;
        const isCoarse = coarseQuery.matches || navigator.maxTouchPoints > 0;
        const isScaledDown = viewport ? viewport.scale < 0.8 : false;
        const isPortrait = window.innerHeight > window.innerWidth;
        const compactScreen = window.screen ? Math.min(window.screen.width, window.screen.height) <= 600 : false;
        const looksLikeDesktopMode = isCoarse && viewportWidth >= 768 && (
            isScaledDown || compactScreen || (isPortrait && viewportWidth <= 1100 && window.devicePixelRatio >= 2.5)
        );

        root.classList.toggle('coarse-touch', isCoarse);
        root.classList.toggle('touch-desktop-site', looksLikeDesktopMode);
    }

    updateDeviceLayout();
    window.addEventListener('resize', updateDeviceLayout, { passive: true });
    window.addEventListener('orientationchange', updateDeviceLayout, { passive: true });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', updateDeviceLayout, { passive: true });
    }
}());
