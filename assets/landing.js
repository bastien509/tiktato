// Landing page: scroll reveals, live-number count-up, copy demo login, nav shadow.
(function () {
  'use strict';
  document.documentElement.classList.add('js');

  var items = Array.prototype.slice.call(document.querySelectorAll('.reveal'));
  var counted = false;
  function countUp() {
    if (counted) return;
    counted = true;
    document.querySelectorAll('[data-count]').forEach(function (el) {
      var to = +el.dataset.count || 0;
      var prefix = el.dataset.prefix || '';
      var t0 = performance.now();
      (function step(t) {
        var k = Math.min(1, (t - t0) / 1400);
        var v = Math.round(to * (1 - Math.pow(1 - k, 3)));
        el.textContent = prefix + v.toLocaleString('en-US');
        if (k < 1) requestAnimationFrame(step);
      })(t0);
    });
  }

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('in');
        if (e.target.closest('.ln-proof')) countUp();
        io.unobserve(e.target);
      });
    }, { threshold: 0.12 });
    items.forEach(function (el, i) {
      el.style.transitionDelay = (i % 4) * 70 + 'ms';
      io.observe(el);
    });
  } else {
    items.forEach(function (el) { el.classList.add('in'); });
    countUp();
  }

  var nav = document.getElementById('lnNav');
  var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 10); };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  var copy = document.getElementById('copyCreds');
  if (copy) {
    copy.addEventListener('click', function () {
      var text = document.getElementById('credUser').textContent + ' / ' + document.getElementById('credPass').textContent;
      var done = function () { copy.textContent = 'Copied ✓'; setTimeout(function () { copy.textContent = 'Copy'; }, 1800); };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, done);
      else done();
    });
  }
})();
