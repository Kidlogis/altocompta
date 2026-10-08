// Serveur d'essai pour les provisions.
//
// L'API de l'Urssaf n'est pas joignable depuis un environnement de test ; ses règles, elles, sont
// publiées (paquet npm modele-social). Ce serveur les exécute avec le même moteur (publicodes) derrière
// la même interface — POST /api/v1/evaluate { situation, expressions } — de sorte que les tests
// éprouvent le vrai calcul plutôt qu'une réponse inventée.
//
//   npm install && PORT=3999 node serveur.mjs
//
// Pannes simulables en préfixant le chemin : /__indisponible, /__limite, /__situation, /__regle,
// /__html, /__lent (5 s). Exemple : base_url = http://127.0.0.1:3999/__regle
import http from 'node:http'
import rules from 'modele-social'
import Engine from 'publicodes'

const port = Number(process.env.PORT || 3999)
const moteur = new Engine(rules)
const envoyer = (res, code, corps, type = 'application/json') => { res.writeHead(code, { 'Content-Type': type }); res.end(typeof corps === 'string' ? corps : JSON.stringify(corps)) }

http.createServer((req, res) => {
  let donnees = ''
  req.on('data', c => donnees += c)
  req.on('end', async () => {
    const m = /^\/__([a-z]+)(\/.*)$/.exec(req.url)
    const panne = m ? m[1] : ''
    const chemin = m ? m[2] : req.url
    if (req.method !== 'POST' || chemin !== '/api/v1/evaluate') return envoyer(res, 404, { error: 'introuvable' })

    if (panne === 'indisponible') return envoyer(res, 503, '')
    if (panne === 'limite') return envoyer(res, 429, { error: 'Too Many Requests' })
    if (panne === 'html') return envoyer(res, 200, '<html>maintenance</html>', 'text/html')
    if (panne === 'situation') return envoyer(res, 200, { situationError: { message: "La règle 'entreprise . inconnue' n'existe pas" } })
    if (panne === 'regle') return envoyer(res, 500, { error: { message: "Unknown rule 'dirigeant . indépendant . cotisations et contributions'" } })
    if (panne === 'lent') await new Promise(r => setTimeout(r, 5000))

    let corps
    try { corps = JSON.parse(donnees) } catch { return envoyer(res, 400, [{ message: 'JSON invalide' }]) }
    if (!corps.expressions) return envoyer(res, 400, [{ message: "must have required property 'expressions'", dataPath: '' }])
    const exprs = Array.isArray(corps.expressions) ? corps.expressions : [corps.expressions]

    try { moteur.setSituation(corps.situation || {}) }
    catch (e) { return envoyer(res, 200, { situationError: { message: String(e.message).slice(0, 300) } }) }

    try {
      const evaluate = exprs.map(x => {
        const r = typeof x === 'string' ? moteur.evaluate(x) : moteur.evaluate({ valeur: x.valeur, unité: x['unité'] })
        return { nodeValue: r.nodeValue ?? null, missingVariables: Object.keys(r.missingVariables || {}) }
      })
      envoyer(res, 200, { evaluate, warnings: [] })
    } catch (e) { envoyer(res, 500, { error: { message: String(e.message).slice(0, 300) } }) }
  })
}).listen(port, '127.0.0.1', () => console.log('urssaf-local sur ' + port))
