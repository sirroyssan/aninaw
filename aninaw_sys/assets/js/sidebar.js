/*
 * Keeps the sidebar menu where the user left it. Every module is a full page load, so
 * without this the menu jumps back to the top after each click. Loaded right after the
 * menu (before the rest of the page) so the position is restored before it is painted.
 * The position is kept per browser tab; if the current module would be out of view,
 * the menu scrolls just enough to show it.
 */
(function () {
    'use strict';

    var nav = document.querySelector('.sidebar-nav');
    if (!nav) {
        return;
    }
    var key = 'aninaw.sidebarScroll';

    try {
        var saved = window.sessionStorage.getItem(key);
        if (saved !== null) {
            nav.scrollTop = parseInt(saved, 10) || 0;
        }
    } catch (error) {
        // Storage unavailable (private mode, blocked): the menu simply starts at the top.
    }

    var active = nav.querySelector('.nav-link-item.active');
    if (active) {
        var navBox = nav.getBoundingClientRect();
        var itemBox = active.getBoundingClientRect();
        if (itemBox.top < navBox.top || itemBox.bottom > navBox.bottom) {
            nav.scrollTop += itemBox.top - navBox.top - (navBox.height - itemBox.height) / 2;
        }
    }

    function save() {
        try {
            window.sessionStorage.setItem(key, String(Math.round(nav.scrollTop)));
        } catch (error) {
            // ignore
        }
    }
    var timer = null;
    nav.addEventListener('scroll', function () {
        clearTimeout(timer);
        timer = setTimeout(save, 100);
    }, { passive: true });
    nav.addEventListener('click', save);
    window.addEventListener('pagehide', save);
})();
