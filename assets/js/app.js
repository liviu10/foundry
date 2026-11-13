/*
 * Foundry docs: progressive enhancement only. Every page is complete HTML without JavaScript.
 * Uses the Alpine.js CSP build: directives reference properties, getters and methods by name only
 * (no inline expressions), so the site runs under a strict Content-Security-Policy (script-src 'self').
 */
(function () {
  'use strict';

  var store = {
    get: function (key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
    set: function (key, value) { try { localStorage.setItem(key, value); } catch (e) { /* ignore */ } }
  };

  var KIND_LABELS = { class: 'Class', method: 'Method', function: 'Function', guide: 'Guide', section: 'Section' };
  var KIND_WEIGHT = { class: 12, guide: 8, function: 4, method: 2, section: 0 };

  function systemDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function isTyping(el) {
    return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
  }

  function normalize(text) {
    return String(text).toLowerCase().replace(/::|->|\(\)|[_\-./\\]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function prepare(entry) {
    entry.d = entry.d || '';
    entry.kl = KIND_LABELS[entry.k] || '';
    entry.tn = normalize(entry.t);
    entry.compact = entry.tn.replace(/ /g, '');
    entry.hay = entry.tn + ' ' + normalize(entry.s) + ' ' + entry.d.toLowerCase();
    return entry;
  }

  function score(entry, q, compactQ, terms) {
    var s = 0;
    for (var i = 0; i < terms.length; i++) {
      if (entry.hay.indexOf(terms[i]) === -1 && entry.compact.indexOf(terms[i]) === -1) { return 0; }
      s += entry.tn.indexOf(terms[i]) !== -1 ? 10 : 2;
    }
    if (entry.tn === q || entry.compact === compactQ) { s += 100; }
    else if (entry.tn.indexOf(q) === 0 || entry.compact.indexOf(compactQ) === 0) { s += 50; }
    else if (entry.tn.indexOf(q) !== -1 || entry.compact.indexOf(compactQ) !== -1) { s += 25; }
    return s + (KIND_WEIGHT[entry.k] || 0) - entry.t.length / 100;
  }

  document.addEventListener('alpine:init', function () {
    window.Alpine.data('site', function () {
      return {
        dark: false,
        drawerOpen: false,
        searchOpen: false,
        query: '',
        results: [],
        active: 0,
        index: null,
        loading: false,
        failed: false,

        init: function () {
          var theme = document.documentElement.getAttribute('data-theme');
          this.dark = theme ? theme === 'dark' : systemDark();
          var q = new URLSearchParams(window.location.search).get('q');
          if (q) {
            this.query = q;
            this.openSearch();
          }
        },

        // ---- theme
        get isDark() { return this.dark; },
        get isLight() { return !this.dark; },
        get themeLabel() { return this.dark ? 'Switch to light theme' : 'Switch to dark theme'; },
        toggleTheme: function () {
          this.dark = !this.dark;
          var theme = this.dark ? 'dark' : 'light';
          document.documentElement.setAttribute('data-theme', theme);
          store.set('foundry-theme', theme);
        },

        // ---- mobile navigation drawer
        get drawerClass() { return this.drawerOpen ? 'is-open' : ''; },
        get drawerExpanded() { return this.drawerOpen ? 'true' : 'false'; },
        toggleDrawer: function () {
          this.drawerOpen = !this.drawerOpen;
          document.documentElement.classList.toggle('no-scroll', this.drawerOpen);
        },
        closeDrawer: function () {
          this.drawerOpen = false;
          document.documentElement.classList.remove('no-scroll');
        },

        // ---- keyboard shortcuts
        onKey: function (e) {
          if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            if (this.searchOpen) { this.closeSearch(); } else { this.openSearch(); }
            return;
          }
          if (e.key === '/' && !this.searchOpen && !isTyping(e.target)) {
            e.preventDefault();
            this.openSearch();
            return;
          }
          if (e.key === 'Escape' && this.drawerOpen) { this.closeDrawer(); }
        },

        // ---- search
        openSearch: function () {
          var self = this;
          this.closeDrawer();
          this.searchOpen = true;
          document.documentElement.classList.add('no-scroll');
          this.load();
          this.$nextTick(function () {
            var input = self.$refs.input;
            input.value = self.query;
            input.focus();
            input.select();
          });
        },
        closeSearch: function () {
          this.searchOpen = false;
          document.documentElement.classList.remove('no-scroll');
        },
        load: function () {
          var self = this;
          if (this.index || this.loading) { this.run(); return; }
          this.loading = true;
          fetch('/search-index.json', { credentials: 'omit' })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (data) { self.index = data.map(prepare); })
            .catch(function () { self.failed = true; self.index = []; })
            .then(function () { self.loading = false; self.run(); });
        },
        onInput: function (e) {
          this.query = e.target.value;
          this.run();
        },
        run: function () {
          var q = normalize(this.query);
          if (!q || !this.index) { this.results = []; return; }
          var compactQ = q.replace(/ /g, '');
          var terms = q.split(' ');
          var scored = [];
          for (var i = 0; i < this.index.length; i++) {
            var s = score(this.index[i], q, compactQ, terms);
            if (s > 0) { scored.push([s, this.index[i]]); }
          }
          scored.sort(function (a, b) { return b[0] - a[0]; });
          this.active = 0;
          this.decorate(scored.slice(0, 40).map(function (pair) { return pair[1]; }));
        },
        decorate: function (list) {
          var active = this.active;
          this.results = list.map(function (r, i) {
            return {
              id: 'sr-' + i, i: i, t: r.t, u: r.u, s: r.s, d: r.d, kl: r.kl,
              cls: i === active ? 'result is-active' : 'result',
              selected: i === active ? 'true' : 'false'
            };
          });
        },
        move: function (delta) {
          var n = this.results.length;
          if (!n) { return; }
          this.active = (this.active + delta + n) % n;
          this.decorate(this.results);
          var list = this.$refs.list;
          this.$nextTick(function () {
            var el = list.querySelector('.is-active');
            if (el) { el.scrollIntoView({ block: 'nearest' }); }
          });
        },
        hover: function (e) {
          var i = Number(e.currentTarget.getAttribute('data-index'));
          if (i !== this.active) {
            this.active = i;
            this.decorate(this.results);
          }
        },
        onSearchKey: function (e) {
          if (e.key === 'ArrowDown') { e.preventDefault(); this.move(1); }
          else if (e.key === 'ArrowUp') { e.preventDefault(); this.move(-1); }
          else if (e.key === 'Escape') { e.preventDefault(); this.closeSearch(); }
          else if (e.key === 'Enter') {
            var hit = this.results[this.active];
            if (hit) {
              e.preventDefault();
              this.closeSearch();
              window.location.href = hit.u;
            }
          }
        },
        get activeId() { return this.results.length ? 'sr-' + this.active : ''; },
        get showStatus() { return this.loading || this.failed || (this.query !== '' && this.results.length === 0) || this.query === ''; },
        get statusText() {
          if (this.loading) { return 'Loading index…'; }
          if (this.failed) { return 'The search index could not be loaded.'; }
          if (this.query === '') { return 'Try “Json::decode”, “csrf”, “iban” or “import”.'; }
          return 'No results for “' + this.query + '”.';
        }
      };
    });
  });

  // ---- copy buttons on code blocks
  function setupCopy() {
    if (!navigator.clipboard) { return; }
    document.querySelectorAll('[data-copy]').forEach(function (btn) { btn.hidden = false; });
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-copy]') : null;
      if (!btn) { return; }
      var pre = btn.closest('.codeblock').querySelector('pre');
      navigator.clipboard.writeText(pre.innerText.replace(/\n$/, '')).then(function () {
        btn.textContent = 'Copied';
        btn.classList.add('is-done');
        setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('is-done'); }, 1600);
      });
    });
  }

  // ---- "On this page": highlight the section being read
  function setupScrollSpy() {
    var toc = document.querySelector('.toc');
    if (!toc) { return; }
    var links = Array.prototype.slice.call(toc.querySelectorAll('a[href^="#"]'));
    var targets = links.map(function (a) { return document.getElementById(decodeURIComponent(a.hash.slice(1))); });
    var current = -1;
    var ticking = false;

    function update() {
      ticking = false;
      var index = 0;
      for (var i = 0; i < targets.length; i++) {
        if (targets[i] && targets[i].getBoundingClientRect().top <= 110) { index = i; } else if (targets[i]) { break; }
      }
      if (index === current) { return; }
      if (current >= 0) { links[current].removeAttribute('aria-current'); }
      current = index;
      var link = links[index];
      link.setAttribute('aria-current', 'location');
      var top = link.offsetTop - toc.clientHeight / 2;
      if (link.offsetTop < toc.scrollTop || link.offsetTop > toc.scrollTop + toc.clientHeight - 40) { toc.scrollTop = top; }
    }
    window.addEventListener('scroll', function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
    }, { passive: true });
    update();
  }

  // ---- keep the active sidebar link in view (without scrolling the page)
  function revealActiveNav() {
    var sidebar = document.querySelector('.sidebar');
    var active = sidebar && sidebar.querySelector('[aria-current="page"]');
    if (active) { sidebar.scrollTop = active.offsetTop - sidebar.clientHeight / 3; }
  }

  function ready() {
    setupCopy();
    setupScrollSpy();
    revealActiveNav();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
