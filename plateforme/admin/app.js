// Confirmation des actions irréversibles (pas de script en ligne : CSP stricte)
document.addEventListener('submit', function (e) {
  var f = e.target;
  if (f.dataset && f.dataset.confirmer && !window.confirm(f.dataset.confirmer)) {
    e.preventDefault();
  }
});
