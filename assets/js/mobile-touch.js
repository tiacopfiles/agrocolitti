(function () {
    'use strict';

    function lockViewportScale() {
        var viewport = document.querySelector('meta[name="viewport"]');
        if (!viewport) {
            viewport = document.createElement('meta');
            viewport.name = 'viewport';
            document.head.appendChild(viewport);
        }
        viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover');
    }

    function isMobileViewport() {
        return window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
    }

    lockViewportScale();

    document.addEventListener('dblclick', function (event) {
        if (isMobileViewport()) {
            event.preventDefault();
        }
    }, { passive: false });

    document.addEventListener('gesturestart', function (event) {
        event.preventDefault();
    }, { passive: false });

    document.addEventListener('gesturechange', function (event) {
        event.preventDefault();
    }, { passive: false });

    document.addEventListener('touchmove', function (event) {
        if (isMobileViewport() && event.touches && event.touches.length > 1) {
            event.preventDefault();
        }
    }, { passive: false });

    var lastTouchEnd = 0;
    document.addEventListener('touchend', function (event) {
        if (!isMobileViewport()) return;

        var now = Date.now();
        if (now - lastTouchEnd <= 350) {
            event.preventDefault();
        }
        lastTouchEnd = now;
    }, { passive: false });
})();
