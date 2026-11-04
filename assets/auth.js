// Login / register helpers: show-password toggle and live avatar preview.
(function () {
  'use strict';
  document.querySelectorAll('[data-peek]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.parentNode.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  var preview = document.querySelector('[data-preview]');
  if (!preview) return;
  function sync() {
    var a = document.querySelector('input[name="avatar"]:checked');
    var c = document.querySelector('input[name="color"]:checked');
    if (a) preview.textContent = a.nextElementSibling.textContent;
    if (c) preview.className = preview.className.replace(/\bav-c\d\b/, 'av-c' + c.value);
    preview.animate([{ transform: 'scale(.85)' }, { transform: 'scale(1.08)' }, { transform: 'scale(1)' }], { duration: 300 });
  }
  document.querySelectorAll('input[name="avatar"], input[name="color"]').forEach(function (r) {
    r.addEventListener('change', sync);
  });
})();
