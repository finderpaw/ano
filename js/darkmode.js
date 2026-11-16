// js/darkmode.js
// Applies the saved dark mode on every page and wires up the switch in settings.
(function () {
    var KEY = 'pawfinder_dark_mode';

    function isEnabled() {
        try { return localStorage.getItem(KEY) === 'enabled'; } catch (e) { return false; }
    }

    function setMode(on) {
        document.body.classList.toggle('dark-mode', on);
        try { localStorage.setItem(KEY, on ? 'enabled' : 'disabled'); } catch (e) {}
    }

    // Apply the saved mode right away (this script runs right after <body> opens)
    document.body.classList.toggle('dark-mode', isEnabled());

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('darkModeToggle');
        if (!toggle) return;
        toggle.checked = isEnabled();
        toggle.addEventListener('change', function () {
            setMode(toggle.checked);
        });
    });
})();