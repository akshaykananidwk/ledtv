/* Public landing page (index.php, 2.6.1): mobile menu, active section in the nav, reveal on scroll,
 * back-to-top button. No dependencies; the page works fully without this script. */
(function () {
  'use strict';
  var doc = document;
  var btn = doc.querySelector('.menu-btn');
  var nav = doc.getElementById('sitenav');

  function closeMenu() {
    if (!nav || !btn) return;
    nav.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
    var i = btn.querySelector('i');
    if (i) i.className = 'bi bi-list';
  }
  if (btn && nav) {
    btn.addEventListener('click', function () {
      var open = !nav.classList.contains('open');
      nav.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      var i = btn.querySelector('i');
      if (i) i.className = open ? 'bi bi-x-lg' : 'bi bi-list';
    });
    nav.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
      if (a) closeMenu();
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && nav.classList.contains('open')) { closeMenu(); btn.focus(); }
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 1180) closeMenu(); });
  }

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!('IntersectionObserver' in window)) return;

  // Highlight the section in view.
  var links = {};
  Array.prototype.forEach.call(doc.querySelectorAll('.navlinks a[href^="#"]'), function (a) {
    links[a.getAttribute('href').slice(1)] = a;
  });
  var spy = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      var a = links[en.target.id];
      if (!a) return;
      if (en.isIntersecting) {
        Object.keys(links).forEach(function (k) { links[k].classList.remove('active'); links[k].removeAttribute('aria-current'); });
        a.classList.add('active');
        a.setAttribute('aria-current', 'location');
      }
    });
  }, { rootMargin: '-40% 0px -55% 0px' });
  Object.keys(links).forEach(function (id) { var s = doc.getElementById(id); if (s) spy.observe(s); });

  // Back to top.
  var top = doc.querySelector('.totop');
  var hero = doc.querySelector('.hero');
  if (top && hero) {
    new IntersectionObserver(function (en) { top.classList.toggle('show', !en[0].isIntersecting); }).observe(hero);
  }

  // Reveal on scroll: only elements below the fold get hidden first, so nothing visible ever flickers.
  if (reduce) return;
  var vh = window.innerHeight || 800;
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) { en.target.classList.remove('pre'); io.unobserve(en.target); }
    });
  }, { rootMargin: '0px 0px -8% 0px' });
  Array.prototype.forEach.call(doc.querySelectorAll('.reveal'), function (el, i) {
    if (el.getBoundingClientRect().top > vh) {
      el.classList.add('pre');
      el.style.transitionDelay = ((i % 4) * 60) + 'ms';
      io.observe(el);
    }
  });
})();
