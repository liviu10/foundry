/* Runs before first paint: applies the saved theme and flags JS support (no flash of wrong theme). */
(function () {
  var root = document.documentElement;
  root.classList.add('js');
  try {
    var theme = localStorage.getItem('foundry-theme');
    if (theme === 'dark' || theme === 'light') {
      root.setAttribute('data-theme', theme);
    }
  } catch (e) { /* storage unavailable: follow the system theme */ }
})();
