/* Triage clavier de l'écran d'affectation.
   Le formulaire reste utilisable sans JavaScript : chaque bouton est un submit. */
(function () {
  'use strict';

  var liste = document.getElementById('triage-liste');
  if (!liste) return;

  var index = 0;

  function lignes() {
    return Array.prototype.slice.call(liste.querySelectorAll('.ligne:not(.partie)'));
  }

  function viser(n) {
    var l = lignes();
    if (!l.length) return;
    index = Math.max(0, Math.min(n, l.length - 1));
    l.forEach(function (x) { x.classList.remove('vise'); });
    l[index].classList.add('vise');
    l[index].scrollIntoView({ block: 'nearest' });
  }

  function agir(role) {
    var l = lignes();
    if (!l[index]) return;
    var btn = l[index].querySelector('[data-role="' + role + '"]');
    if (!btn) return;

    // Une dépense sans poste choisi : on ouvre le sélecteur au lieu de soumettre.
    if (role === 'depense') {
      var sel = l[index].querySelector('.form-depense select');
      if (sel && !sel.value) { sel.focus(); return; }
    }
    l[index].classList.add('partie');
    btn.form.submit();
  }

  document.addEventListener('keydown', function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (t && /^(INPUT|SELECT|TEXTAREA)$/.test(t.tagName)) return;

    var k = e.key.toLowerCase();
    if (k === 'arrowdown' || k === 'j')      { viser(index + 1); }
    else if (k === 'arrowup' || k === 'k')   { viser(index - 1); }
    else if (k === 'r')                      { agir('recette'); }
    else if (k === 'd')                      { agir('depense'); }
    else if (k === 'm')                      { agir('immobilisation'); }
    else if (k === 'v')                      { agir('virement'); }
    else if (k === 'i')                      { agir('ignorer'); }
    else return;

    e.preventDefault();
  });

  liste.addEventListener('click', function (e) {
    var ligne = e.target.closest('.ligne');
    if (!ligne) return;
    viser(lignes().indexOf(ligne));
  });
})();

/* Fiche d'une écriture, assujetti à la TVA : calculer le HT et la TVA depuis le TTC et le taux.
   Le serveur recalcule et contrôle tout : ce bouton n'est qu'un confort de saisie. */
(function () {
  'use strict';

  var bloc = document.querySelector('[data-tva]');
  if (!bloc) return;

  var montant = document.getElementById('m');
  var taux = document.getElementById('tva-taux');
  var ht = document.getElementById('tva-ht');
  var tva = document.getElementById('tva-tva');
  var bouton = document.getElementById('tva-calculer');
  if (!montant || !taux || !ht || !tva || !bouton) return;

  function nombre(v) {
    var n = parseFloat(String(v || '').replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? null : n;
  }

  function fr(centimes) {
    return (centimes / 100).toFixed(2).replace('.', ',');
  }

  function calculer() {
    var ttc = nombre(montant.value);
    var r = nombre(taux.value);
    if (ttc === null || r === null || r < 0) return;
    var ttcC = Math.round(ttc * 100);
    var htC = Math.round(ttcC / (1 + r / 100));
    ht.value = fr(htC);
    tva.value = fr(ttcC - htC);
  }

  bouton.addEventListener('click', calculer);
  taux.addEventListener('change', function () {
    if (!ht.value && !tva.value) calculer();   // ne jamais écraser ce que la facture a donné
  });
})();
