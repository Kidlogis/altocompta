#!/bin/sh
# Suite de tests unitaires. Aucune base de données requise.
#   sh tests/lancer.sh
cd "$(dirname "$0")/.." || exit 1
ko=0
for t in tests/*.php; do
  printf '%-24s' "$(basename "$t" .php)"
  if php "$t" > /tmp/sortie.$$ 2>&1; then
    echo "ok   $(grep -c '  ok' /tmp/sortie.$$) assertions"
  else
    echo "ECHEC"
    grep 'FAIL' /tmp/sortie.$$ | sed 's/^/    /'
    ko=1
  fi
done
rm -f /tmp/sortie.$$
[ $ko -eq 0 ] && echo "Tout passe." || echo "Des tests ont échoué."
exit $ko
