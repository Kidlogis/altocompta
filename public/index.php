<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Bnc\Affectation;
use Bnc\Archive;
use Bnc\Auth;
use Bnc\CategorieMetier;
use Bnc\CompteBancaire;
use Bnc\VirementInterne;
use Bnc\BaremeKm;
use Bnc\Vehicule;
use Bnc\Database;
use Bnc\Declaration;
use Bnc\Declaration2035E;
use Bnc\RegistrePdf;
use Bnc\Depense;
use Bnc\Doublons;
use Bnc\Export;
use Bnc\Exercice;
use Bnc\Import;
use Bnc\Journal;
use Bnc\Justificatif;
use Bnc\MiseAJour;
use Bnc\Montant;
use Bnc\Pagination;
use Bnc\Plugin;
use Bnc\OfxParser;
use Bnc\Poste2035;
use Bnc\Boutique;
use Bnc\ExtensionZip;
use Bnc\Provisions;
use Bnc\Tva;
use Bnc\TvaPeriodes;
use Bnc\Urssaf;
use Bnc\Recette;
use Bnc\Regularisation;
use Bnc\Regle;
use Bnc\ReleveParser;
use Bnc\Router;
use Bnc\Utilisateur;
use Bnc\View;

$r = new Router();

/** Contexte commun à toutes les pages authentifiées. */
function contexte(string $actif, array $extra = []): array
{
    $uid = Auth::userId();
    $ex  = Exercice::actif($uid);
    return [
        'actif'      => $actif,
        'exercice'   => $ex,
        'exercices'  => Exercice::lister($uid),
        'a_affecter' => Affectation::nbEnAttente($uid, (int) $ex['id']),
        'uid'        => $uid,
        'tva'        => Tva::actif($uid),    // assujetti : les fiches proposent l'éclatement HT / TVA
    ] + $extra;
}

function flash(string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirige(string $url): never { header('Location: ' . $url); exit; }

// =====================================================================
//  Connexion
// =====================================================================
$r->get('/connexion', function (): void {
    if (Auth::connecte()) redirige('/');
    View::rendre('auth/connexion', ['erreur' => null], null);
});

$r->post('/connexion', function (): void {
    Auth::exigerJeton();
    if (Auth::tenterConnexion((string) ($_POST['email'] ?? ''), (string) ($_POST['mot_de_passe'] ?? ''))) {
        redirige('/');
    }
    http_response_code(401);
    View::rendre('auth/connexion', ['erreur' => 'Adresse e-mail ou mot de passe incorrect.'], null);
});

$r->post('/deconnexion', function (): void {
    Auth::exigerJeton();
    Auth::deconnecter();
    redirige('/connexion');
});

$r->post('/exercice', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $id = (int) ($_POST['exercice_id'] ?? 0);
    if (Exercice::trouver(Auth::userId(), $id) !== null) Auth::definirExercice($id);
    redirige(Auth::cheminLocal((string) ($_POST['retour'] ?? '/')));
});

$r->post('/exercice/creer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $annee   = (int) ($_POST['annee'] ?? 0);
    $retour  = Auth::cheminLocal((string) ($_POST['retour'] ?? '/'));
    $courante = (int) date('Y');

    if (!Exercice::anneeValide($annee, $courante)) {
        flash('Année invalide : de ' . Exercice::ANNEE_MIN . ' à ' . ($courante + 1) . '.', 'ko');
        redirige($retour);
    }
    if (Database::valeur('SELECT id FROM exercices WHERE user_id = :u AND annee = :a',
                         ['u' => Auth::userId(), 'a' => $annee])) {
        flash("L'exercice {$annee} existe déjà.", 'ko');
        redirige($retour);
    }

    Auth::definirExercice(Exercice::creer(Auth::userId(), $annee));
    flash("Exercice {$annee} créé : il est maintenant affiché.");
    redirige($retour);
});

// =====================================================================
//  Tableau de bord
// =====================================================================
$r->get('/', function (): void {
    Auth::exiger();
    $c = contexte('bord');
    $uid = $c['uid']; $exId = (int) $c['exercice']['id'];

    $recettes = Recette::total($uid, $exId);
    $depenses = Depense::total($uid, $exId);

    View::rendre('bord/index', $c + [
        'titre_page'  => 'Tableau de bord',
        'flash'       => flash(),
        'recettes'    => $recettes,
        'depenses'    => $depenses,
        'resultat'    => $recettes - $depenses,
        'nb_recettes' => Recette::compter($uid, $exId),
        'nb_depenses' => Depense::compter($uid, $exId),
        'sans_justif' => Depense::nbSansJustificatif($uid, $exId),
        'dernieres'   => Database::tous(
            "SELECT date_encaissement, client, nature, montant FROM recettes
              WHERE user_id=:u AND exercice_id=:e AND statut = 'active'
              ORDER BY date_encaissement DESC, id DESC LIMIT 6",
            ['u' => $uid, 'e' => $exId]),
        'postes'      => array_slice(Depense::parPoste($uid, $exId), 0, 6),
    ]);
});

// =====================================================================
//  Recettes
// =====================================================================
$r->get('/recettes', function (): void {
    Auth::exiger();
    $c = contexte('recettes');
    $q       = trim((string) ($_GET['q'] ?? ''));
    $epure   = !empty($_GET['epure']);
    $aVentiler = !empty($_GET['a_ventiler']);
    $filtres = ['q' => $q, 'sans_corrections' => $epure, 'a_ventiler' => $aVentiler];
    $exId    = (int) $c['exercice']['id'];

    $nb   = Recette::compter($c['uid'], $exId, $filtres);
    $page = min(Pagination::page(), Pagination::nbPages($nb));

    View::rendre('recettes/liste', $c + [
        'titre_page' => 'Livre des recettes',
        'flash'      => flash(),
        'q'          => $q,
        'epure'      => $epure,
        'a_ventiler' => $aVentiler,
        'nb_a_ventiler' => Tva::nbAVentiler($c['uid'], $exId)['recettes'],
        'totaux'     => $c['tva'] ? Tva::totaux($c['uid'], $exId, false) : null,
        'lignes'     => Recette::lister($c['uid'], $exId, $filtres,
                            Pagination::PAR_PAGE, Pagination::decalage($page)),
        'nb'         => $nb,
        'nb_total'   => Recette::compter($c['uid'], $exId, ['q' => $q, 'a_ventiler' => $aVentiler]),
        'nb_actives' => Recette::compter($c['uid'], $exId, ['q' => $q, 'a_ventiler' => $aVentiler, 'sans_corrections' => true]),
        'page'       => $page,
        'nb_pages'   => Pagination::nbPages($nb),
        'total'      => Recette::total($c['uid'], $exId),
    ]);
});

$r->get('/recettes/nouvelle', function (): void {
    Auth::exiger();
    $c = contexte('recettes');
    Exercice::exigerOuvert($c['exercice']);
    View::rendre('recettes/form', $c + [
        'titre_page' => 'Nouvelle recette',
        'ligne'      => null, 'erreurs' => [],
        'postes'     => Poste2035::saisissables('recette'),
        'categories' => CategorieMetier::lister($c['uid'], 'recette'),
    ]);
});

$r->get('/recettes/{id:\d+}/modifier', function (array $p): void {
    Auth::exiger();
    $c = contexte('recettes');
    $ligne = Recette::trouver($c['uid'], (int) $p['id']);
    if ($ligne === null) { http_response_code(404); redirige('/recettes'); }
    View::rendre('recettes/form', $c + [
        'titre_page' => 'Recette n° ' . $ligne['numero_chrono'],
        'ligne'      => $ligne, 'erreurs' => [],
        'postes'     => Poste2035::saisissables('recette'),
        'categories' => CategorieMetier::lister($c['uid'], 'recette'),
    ]);
});

$r->post('/recettes/enregistrer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('recettes');
    Exercice::exigerOuvert($c['exercice']);

    $id = (int) ($_POST['id'] ?? 0);
    [$d, $erreurs] = validerRecette($_POST);

    if ($erreurs) {
        $ligne = $id ? Recette::trouver($c['uid'], $id) : null;
        View::rendre('recettes/form', $c + [
            'titre_page' => $id ? 'Recette' : 'Nouvelle recette',
            'ligne'      => array_merge($ligne ?? [], $_POST),
            'erreurs'    => $erreurs,
            'postes'     => Poste2035::saisissables('recette'),
            'categories' => CategorieMetier::lister($c['uid'], 'recette'),
        ]);
        return;
    }

    if ($id && Recette::trouver($c['uid'], $id)) {
        // Rectification : l'écriture d'origine est conservée et annulée,
        // une écriture corrigée la remplace, reliée à elle.
        [$nouveau, $err] = Recette::rectifier($c['uid'], $id, $d,
            trim((string) ($_POST['motif'] ?? '')));
        if ($err !== '') {
            flash($err, 'ko');
            redirige('/recettes');
        }
        flash('Recette rectifiée. L\'écriture d\'origine reste consultable, marquée annulée.');
    } else {
        Recette::creer($c['uid'], (int) $c['exercice']['id'], $d);
        flash('Recette enregistrée.');
    }
    redirige('/recettes');
});

$r->post('/recettes/{id:\d+}/annuler', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('recettes');
    Exercice::exigerOuvert($c['exercice']);

    $err = Recette::annuler($c['uid'], (int) $p['id'], trim((string) ($_POST['motif'] ?? '')));
    flash($err === ''
        ? 'Recette annulée. Elle garde son numéro et reste consultable : la continuité du livre l\'exige.'
        : $err, $err === '' ? 'ok' : 'ko');
    redirige('/recettes');
});

function validerRecette(array $in): array
{
    $e = [];
    $date = Montant::lireDate((string) ($in['date_encaissement'] ?? ''));
    if ($date === null)                          $e[] = 'Date d\'encaissement invalide.';
    if (trim((string) ($in['client'] ?? '')) === '') $e[] = 'Le client est obligatoire.';
    if (trim((string) ($in['nature'] ?? '')) === '') $e[] = 'La nature de la prestation est obligatoire.';

    // Un montant negatif est licite : c'est un avoir consenti au client.
    $montant = Montant::lire((string) ($in['montant'] ?? ''));
    if ($montant === null)                       $e[] = 'Le montant doit être un nombre.';
    elseif (abs($montant) < 0.01)                $e[] = 'Le montant ne peut pas être nul.';

    // Le poste découle de la catégorie métier quand elle est renseignée.
    $categorieId = (int) ($in['categorie_id'] ?? 0);
    $poste = (string) ($in['poste_2035'] ?? 'AA');
    if ($categorieId > 0) {
        $poste = CategorieMetier::poste(Auth::userId(), $categorieId) ?? $poste;
    }
    if (!Poste2035::existe($poste, 'recette'))   $poste = 'AA';

    // Assujetti : l'éclatement HT / TVA saisi sur la fiche. Le montant reste le TTC du virement.
    [$ventil, $ev] = Tva::actif(Auth::userId()) ? Tva::lire($in, $montant, false) : [array_fill_keys(Tva::CHAMPS_RECETTE, null), []];
    $e = array_merge($e, $ev);

    return [$ventil + [
        'date_encaissement' => $date,
        'client'            => mb_substr(trim((string) $in['client']), 0, 190),
        'nature'            => mb_substr(trim((string) $in['nature']), 0, 255),
        'montant'           => $montant,
        'est_avoir'         => ($montant ?? 0) < 0 ? 1 : 0,
        'mode_reglement'    => in_array($in['mode_reglement'] ?? '', ['virement','cheque','carte','especes','prelevement','autre'], true)
                                ? $in['mode_reglement'] : 'virement',
        'poste_2035'        => $poste,
        'categorie_id'      => $categorieId ?: null,
        'reference_facture' => mb_substr(trim((string) ($in['reference_facture'] ?? '')), 0, 64) ?: null,
        'notes'             => trim((string) ($in['notes'] ?? '')) ?: null,
    ], $e];
}

// =====================================================================
//  Dépenses
// =====================================================================
$r->get('/depenses', function (): void {
    Auth::exiger();
    $c = contexte('depenses');
    $filtres = [
        'q'                 => trim((string) ($_GET['q'] ?? '')),
        'poste'             => (string) ($_GET['poste'] ?? ''),
        'sans_justificatif' => !empty($_GET['sans_justif']),
        'sans_corrections'  => !empty($_GET['epure']),
        'a_ventiler'        => !empty($_GET['a_ventiler']),
    ];
    $exId = (int) $c['exercice']['id'];
    $nb   = Depense::compter($c['uid'], $exId, $filtres);
    $page = min(Pagination::page(), Pagination::nbPages($nb));

    View::rendre('depenses/liste', $c + [
        'titre_page' => 'Registre des dépenses',
        'flash'      => flash(),
        'filtres'    => $filtres,
        'epure'      => !empty($filtres['sans_corrections']),
        'nb_a_ventiler' => Tva::nbAVentiler($c['uid'], $exId)['depenses'],
        'totaux'     => $c['tva'] ? Tva::totaux($c['uid'], $exId, true) : null,
        'lignes'     => Depense::lister($c['uid'], $exId, $filtres,
                            Pagination::PAR_PAGE, Pagination::decalage($page)),
        'nb'         => $nb,
        'nb_total'   => Depense::compter($c['uid'], $exId, ['q' => $filtres['q'], 'poste' => $filtres['poste'],
                            'sans_justificatif' => $filtres['sans_justificatif'], 'a_ventiler' => $filtres['a_ventiler']]),
        'nb_actives' => Depense::compter($c['uid'], $exId, ['q' => $filtres['q'], 'poste' => $filtres['poste'],
                            'sans_justificatif' => $filtres['sans_justificatif'], 'a_ventiler' => $filtres['a_ventiler'], 'sans_corrections' => true]),
        'page'       => $page,
        'nb_pages'   => Pagination::nbPages($nb),
        'total'      => Depense::total($c['uid'], $exId),
        'postes'     => Poste2035::saisissables('depense'),
    ]);
});

$r->get('/depenses/nouvelle', function (): void {
    Auth::exiger();
    $c = contexte('depenses');
    Exercice::exigerOuvert($c['exercice']);
    View::rendre('depenses/form', $c + [
        'titre_page'    => 'Nouvelle dépense',
        'ligne'         => null, 'erreurs' => [],
        'postes'        => Poste2035::saisissables('depense'),
        'categories'    => CategorieMetier::lister($c['uid'], 'depense'),
        'justificatifs' => [],
    ]);
});

$r->get('/depenses/{id:\d+}/modifier', function (array $p): void {
    Auth::exiger();
    $c = contexte('depenses');
    $ligne = Depense::trouver($c['uid'], (int) $p['id']);
    if ($ligne === null) { http_response_code(404); redirige('/depenses'); }
    View::rendre('depenses/form', $c + [
        'titre_page'    => 'Dépense du ' . View::date($ligne['date_decaissement']),
        'ligne'         => $ligne, 'erreurs' => [],
        'postes'        => Poste2035::saisissables('depense'),
        'categories'    => CategorieMetier::lister($c['uid'], 'depense'),
        'justificatifs' => Justificatif::pourDepense($c['uid'], (int) $ligne['id']),
    ]);
});

$r->post('/depenses/enregistrer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('depenses');
    Exercice::exigerOuvert($c['exercice']);

    $id = (int) ($_POST['id'] ?? 0);
    [$d, $erreurs] = validerDepense($_POST);

    if ($erreurs) {
        View::rendre('depenses/form', $c + [
            'titre_page'    => $id ? 'Dépense' : 'Nouvelle dépense',
            'ligne'         => array_merge($id ? (Depense::trouver($c['uid'], $id) ?? []) : [], $_POST),
            'erreurs'       => $erreurs,
            'postes'        => Poste2035::saisissables('depense'),
            'categories'    => CategorieMetier::lister($c['uid'], 'depense'),
            'justificatifs' => $id ? Justificatif::pourDepense($c['uid'], $id) : [],
        ]);
        return;
    }

    if ($id && Depense::trouver($c['uid'], $id)) {
        // Rectification : l'originale est conservée, annulée, et remplacée
        // par une écriture corrigée qui lui reste rattachée.
        [$nouveau, $err] = Depense::rectifier($c['uid'], $id, $d,
            trim((string) ($_POST['motif'] ?? '')));
        if ($err !== '') {
            flash($err, 'ko');
            redirige('/depenses');
        }
        $id = (int) $nouveau;
    } else {
        $id = Depense::creer($c['uid'], (int) $c['exercice']['id'], $d);
    }

    if (!empty($_FILES['justificatif']['name'])) {
        $err = Justificatif::ajouter($c['uid'], $_FILES['justificatif'], $id);
        flash($err === '' ? 'Dépense enregistrée avec son justificatif.' : $err, $err === '' ? 'ok' : 'ko');
    } else {
        flash('Dépense enregistrée.');
    }
    redirige('/depenses');
});

$r->post('/depenses/{id:\d+}/annuler', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('depenses');
    Exercice::exigerOuvert($c['exercice']);

    $err = Depense::annuler($c['uid'], (int) $p['id'], trim((string) ($_POST['motif'] ?? '')));
    flash($err === ''
        ? 'Dépense annulée. Elle reste consultable, avec ses justificatifs.'
        : $err, $err === '' ? 'ok' : 'ko');
    redirige('/depenses');
});

function validerDepense(array $in): array
{
    $e = [];
    $date = Montant::lireDate((string) ($in['date_decaissement'] ?? ''));
    if ($date === null)                                    $e[] = 'Date de décaissement invalide.';
    if (trim((string) ($in['fournisseur'] ?? '')) === '')  $e[] = 'Le fournisseur est obligatoire.';
    if (trim((string) ($in['libelle'] ?? '')) === '')      $e[] = 'Le libellé est obligatoire.';

    // Un montant negatif est licite : c'est un remboursement recu du fournisseur.
    $montant = Montant::lire((string) ($in['montant'] ?? ''));
    if ($montant === null)                                 $e[] = 'Le montant doit être un nombre.';
    elseif (abs($montant) < 0.01)                          $e[] = 'Le montant ne peut pas être nul.';

    $categorieId = (int) ($in['categorie_id'] ?? 0);
    $poste = (string) ($in['poste_2035'] ?? '');
    if ($categorieId > 0) {
        $poste = CategorieMetier::poste(Auth::userId(), $categorieId) ?? $poste;
    }
    if (!Poste2035::existe($poste, 'depense'))             $e[] = 'Choisir une catégorie.';

    $qp = (int) ($in['quote_part_pro'] ?? 100);
    if ($qp < 1 || $qp > 100)                              $e[] = 'La quote-part professionnelle va de 1 à 100 %.';

    // Assujetti : l'éclatement HT / TVA saisi sur la fiche. Le montant reste le TTC payé.
    [$ventil, $ev] = Tva::actif(Auth::userId()) ? Tva::lire($in, $montant, true) : [array_fill_keys(Tva::CHAMPS_DEPENSE, null), []];
    $e = array_merge($e, $ev);

    return [$ventil + [
        'date_decaissement' => $date,
        'fournisseur'       => mb_substr(trim((string) $in['fournisseur']), 0, 190),
        'libelle'           => mb_substr(trim((string) $in['libelle']), 0, 255),
        'montant'           => $montant,
        'est_remboursement' => ($montant ?? 0) < 0 ? 1 : 0,
        'poste_2035'        => $poste,
        'categorie_id'      => $categorieId ?: null,
        'quote_part_pro'    => $qp,
        'mode_reglement'    => in_array($in['mode_reglement'] ?? '', ['virement','cheque','carte','especes','prelevement','autre'], true)
                                ? $in['mode_reglement'] : 'carte',
        'notes'             => trim((string) ($in['notes'] ?? '')) ?: null,
    ], $e];
}

// --- Justificatifs ---------------------------------------------------

$r->get('/justificatif/{id:\d+}', function (array $p): void {
    Auth::exiger();
    $j = Justificatif::trouver(Auth::userId(), (int) $p['id']);
    if ($j === null) { http_response_code(404); exit('Justificatif introuvable.'); }
    Justificatif::servir($j);
});

$r->post('/justificatif/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $j = Justificatif::trouver(Auth::userId(), (int) $p['id']);
    Justificatif::supprimer(Auth::userId(), (int) $p['id']);
    flash('Justificatif supprimé.');
    redirige($j && $j['depense_id'] ? '/depenses/' . $j['depense_id'] . '/modifier' : '/depenses');
});

$r->post('/depenses/{id:\d+}/justificatif', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $uid = Auth::userId();
    if (Depense::trouver($uid, (int) $p['id']) === null) redirige('/depenses');
    $err = Justificatif::ajouter($uid, $_FILES['justificatif'] ?? [], (int) $p['id']);
    flash($err === '' ? 'Justificatif ajouté.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/depenses/' . (int) $p['id'] . '/modifier');
});

// =====================================================================
//  Affectation
// =====================================================================
$r->get('/affectation', function (): void {
    Auth::exiger();
    $c = contexte('triage');
    $lignes = Affectation::enAttente($c['uid'], (int) $c['exercice']['id']);
    View::rendre('affectation/index', $c + [
        'titre_page' => 'Opérations à affecter',
        'flash'      => flash(),
        'lignes'     => $lignes,
        'justifs'    => Justificatif::comptesParTransaction($c['uid'], array_column($lignes, 'id')),
        'regles'     => Regle::lister($c['uid']),
        'postes'     => Poste2035::saisissables('depense'),
        'categories' => CategorieMetier::lister($c['uid'], 'depense'),
        'nb_exclues' => Affectation::nbExclues($c['uid'], (int) $c['exercice']['id']),
        'nb_doublons' => Doublons::compter($c['uid']),
    ]);
});

$r->get('/affectation/exclues', function (): void {
    Auth::exiger();
    $c = contexte('triage');
    View::rendre('affectation/exclues', $c + [
        'titre_page' => 'Opérations exclues',
        'flash'      => flash(),
        'lignes'     => Affectation::exclues($c['uid'], (int) $c['exercice']['id']),
    ]);
});

$r->post('/affectation/{id:\d+}/rouvrir', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $err = Affectation::rouvrir(Auth::userId(), (int) $p['id']);
    flash($err === '' ? 'Opération rouverte, elle est de retour dans la file d\'attente.' : $err,
          $err === '' ? 'ok' : 'ko');
    redirige('/affectation/exclues');
});

$r->post('/affectation/{id:\d+}', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $type  = (string) ($_POST['type'] ?? '');
    $poste = (string) ($_POST['poste_2035'] ?? '') ?: null;

    if (!in_array($type, ['recette', 'depense', 'immobilisation', 'virement_interne', 'hors_bnc'], true)) {
        flash('Action inconnue.', 'ko');
        redirige('/affectation');
    }
    $err = Affectation::affecter(Auth::userId(), (int) $p['id'], $type, $poste,
        (string) ($_POST['motif'] ?? 'prive'), (int) ($_POST['categorie_id'] ?? 0));

    // Une immobilisation demande durée et mode : on passe par le formulaire.
    if (str_starts_with($err, 'REDIRECTION:')) {
        redirige(substr($err, 12));
    }
    if ($err !== '') flash($err, 'ko');

    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $err === '', 'erreur' => $err]);
        exit;
    }
    redirige('/affectation');
});

$r->post('/affectation/{id:\d+}/justificatif', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $uid = Auth::userId();

    if (Affectation::trouver($uid, (int) $p['id']) === null) {
        flash('Opération introuvable.', 'ko');
        redirige('/affectation');
    }
    $err = Justificatif::ajouter($uid, $_FILES['justificatif'] ?? [], null, null, (int) $p['id']);
    flash($err === '' ? 'Justificatif joint à l\'opération.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/affectation');
});

$r->post('/affectation/valider-suggestions', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('triage');
    $n = Affectation::validerSuggestions($c['uid'], (int) $c['exercice']['id']);
    flash($n > 0 ? "{$n} opération" . ($n > 1 ? 's' : '') . " affectée" . ($n > 1 ? 's' : '') . '.'
                 : 'Aucune suggestion applicable : les dépenses sans poste restent à trancher.');
    redirige('/affectation');
});

$r->post('/affectation/tout-ignorer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('triage');
    foreach (Affectation::enAttente($c['uid'], (int) $c['exercice']['id'], 500) as $tx) {
        Affectation::ignorer($c['uid'], (int) $tx['id']);
    }
    flash('Opérations écartées.');
    redirige('/affectation');
});

// --- Règles ----------------------------------------------------------

$r->post('/regles/creer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $type  = (string) ($_POST['type_match'] ?? 'contient');
    $motif = trim((string) ($_POST['motif'] ?? ''));
    $err   = Regle::valider($type, $motif);

    if ($err === '') {
        Regle::creer(Auth::userId(), [
            'motif'        => mb_substr($motif, 0, 190),
            'type_match'   => $type,
            'sens_montant' => (string) ($_POST['sens_montant'] ?? 'tous'),
            'cible_type'   => (string) ($_POST['cible_type'] ?? 'depense'),
            'poste_2035'   => (string) ($_POST['poste_2035'] ?? ''),
            'priorite'     => (int) ($_POST['priorite'] ?? 100),
        ]);
        flash('Règle ajoutée. Elle s\'appliquera aux prochaines opérations importées.');
    } else {
        flash($err, 'ko');
    }
    redirige('/affectation');
});

$r->post('/regles/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    Regle::supprimer(Auth::userId(), (int) $p['id']);
    redirige('/affectation');
});

$r->post('/regles/{id:\d+}/basculer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    Regle::basculer(Auth::userId(), (int) $p['id']);
    redirige('/affectation');
});

// --- Import manuel d'un relevé --------------------------------------

$r->post('/import/releve', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('import');

    $f = $_FILES['releve'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Aucun fichier reçu.', 'ko');
        redirige('/import');
    }

    $compteId = (int) ($_POST['compte_id'] ?? 0);
    if ($compteId > 0 && CompteBancaire::trouver($c['uid'], $compteId) === null) {
        $compteId = 0;
    }
    $res = Import::depuisFichier($c['uid'], (string) file_get_contents($f['tmp_name']),
        $compteId ?: null);
    flash($res['message'], $res['ok'] ? 'ok' : 'ko');
    redirige($res['nouveaux'] > 0 ? '/affectation' : '/import');
});

$r->get('/import', function (): void {
    Auth::exiger();
    $c = contexte('import');
    View::rendre('import/index', $c + [
        'titre_page' => 'Importer un relevé',
        'flash'      => flash(),
        'comptes'    => CompteBancaire::lister($c['uid'], true),
    ]);
});

// =====================================================================
//  Déclaration 2035
// =====================================================================
$r->get('/declaration', function (): void {
    Auth::exiger();
    $c    = contexte('2035');
    $calc = Declaration::calculer($c['uid'], (int) $c['exercice']['id']);

    View::rendre('declaration/index', $c + [
        'titre_page' => 'Formulaire 2035',
        'flash'      => flash(),
        'calc'       => $calc,
        'lignes'     => Declaration::lignes($calc),
        'resultat'   => $calc['resultat'],
    ]);
});

$r->get('/declaration/pdf', function (): void {
    Auth::exiger();
    $c    = contexte('2035');
    $calc = Declaration::calculer($c['uid'], (int) $c['exercice']['id']);

    Export::envoyer(
        Export::declarationPdf(Auth::utilisateur(), $c['exercice'], $calc),
        sprintf('2035-%d.pdf', $c['exercice']['annee']),
        'application/pdf'
    );
});

$r->get('/recettes/export', function (): void {
    Auth::exiger();
    $c = contexte('recettes');
    Export::envoyer(
        Export::recettesCsv($c['uid'], (int) $c['exercice']['id']),
        sprintf('livre-recettes-%d.csv', $c['exercice']['annee']),
        'text/csv; charset=UTF-8'
    );
});

$r->get('/depenses/export', function (): void {
    Auth::exiger();
    $c = contexte('depenses');
    Export::envoyer(
        Export::depensesCsv($c['uid'], (int) $c['exercice']['id']),
        sprintf('registre-depenses-%d.csv', $c['exercice']['annee']),
        'text/csv; charset=UTF-8'
    );
});


$r->get('/trajets/export', function (): void {
    Auth::exiger();
    $c = contexte('vehicules');
    Export::envoyer(
        Export::trajetsCsv($c['uid'], (int) $c['exercice']['id'], (int) $c['exercice']['annee']),
        sprintf('trajets-%d.csv', $c['exercice']['annee']),
        'text/csv; charset=UTF-8'
    );
});

$r->post('/exercice/clore', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('2035');

    [$archiveId, $err] = Exercice::clore($c['uid'], (int) $c['exercice']['id']);
    flash($err === ''
        ? 'Exercice ' . $c['exercice']['annee'] . ' clos et archivé : son contenu est figé et empreint.'
        : $err, $err === '' ? 'ok' : 'ko');
    redirige('/archives');
});

$r->post('/exercice/rouvrir', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('2035');
    Exercice::rouvrir($c['uid'], (int) $c['exercice']['id']);
    flash('Exercice ' . $c['exercice']['annee'] . ' rouvert.');
    redirige('/declaration');
});

// =====================================================================
//  Profil et comptes
// =====================================================================
$r->get('/profil', function (): void {
    Auth::exiger();
    View::rendre('compte/profil', contexte('profil') + [
        'titre_page' => 'Mon compte',
        'flash'      => flash(),
        'moi'        => Auth::utilisateur(),
        'erreurs'    => [],
    ]);
});

$r->post('/profil', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $res = Utilisateur::enregistrerProfil(Auth::userId(), $_POST);

    if (!$res['erreurs']) {
        // Les avertissements ne bloquent rien : la fiche est enregistrée, et
        // ce qui ressemble à une faute de frappe est signalé à côté.
        flash($res['avertissements']
            ? 'Fiche enregistrée. À vérifier : ' . implode(' ', $res['avertissements'])
            : 'Fiche entreprise enregistrée.',
            $res['avertissements'] ? 'att' : 'ok');
        redirige('/profil');
    }

    // Erreur : on réaffiche ce que la personne a saisi, pas la version en base.
    View::rendre('compte/profil', contexte('profil') + [
        'titre_page' => 'Mon compte',
        'moi'        => array_merge(Auth::utilisateur() ?? [], $_POST),
        'erreurs'    => $res['erreurs'],
    ]);
});

$r->post('/profil/mot-de-passe', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $erreurs = Utilisateur::changerMotDePasse(
        Auth::userId(),
        (string) ($_POST['actuel'] ?? ''),
        (string) ($_POST['nouveau'] ?? ''),
        (string) ($_POST['confirmation'] ?? '')
    );
    flash($erreurs ? $erreurs[0] : 'Mot de passe changé.', $erreurs ? 'ko' : 'ok');
    redirige('/profil');
});

/** Réservé aux administrateurs. */
function exigerAdmin(): void
{
    if (!Utilisateur::estAdmin(Auth::utilisateur())) {
        http_response_code(403);
        View::rendre('erreur', [
            'titre_page' => 'Accès refusé',
            'titre'      => 'Accès refusé',
            'message'    => 'La gestion des comptes est réservée aux administrateurs.',
        ], 'layout/nu');
        exit;
    }
}

$r->get('/utilisateurs', function (): void {
    Auth::exiger(); exigerAdmin();
    View::rendre('compte/utilisateurs', contexte('utilisateurs') + [
        'titre_page' => 'Comptes',
        'flash'      => flash(),
        'comptes'    => Utilisateur::lister(),
        'moi'        => Auth::utilisateur(),
        'erreurs'    => [],
    ]);
});

$r->post('/utilisateurs/creer', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    [$id, $erreurs] = Utilisateur::creer($_POST, Auth::userId());
    if ($id !== null) {
        flash('Compte créé, avec un exercice ' . date('Y') . ' ouvert.');
        redirige('/utilisateurs');
    }
    View::rendre('compte/utilisateurs', contexte('utilisateurs') + [
        'titre_page' => 'Comptes',
        'comptes'    => Utilisateur::lister(),
        'moi'        => Auth::utilisateur(),
        'erreurs'    => $erreurs,
    ]);
});

$r->post('/utilisateurs/{id:\d+}/basculer', function (array $p): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $erreurs = Utilisateur::basculerActif((int) $p['id'], Auth::userId());
    flash($erreurs ? $erreurs[0] : 'Compte mis à jour.', $erreurs ? 'ko' : 'ok');
    redirige('/utilisateurs');
});

$r->post('/utilisateurs/{id:\d+}/mot-de-passe', function (array $p): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $erreurs = Utilisateur::reinitialiserMotDePasse((int) $p['id'], (string) ($_POST['nouveau'] ?? ''));
    flash($erreurs ? $erreurs[0] : 'Mot de passe réinitialisé. Le communiquer à la personne concernée.',
          $erreurs ? 'ko' : 'ok');
    redirige('/utilisateurs');
});

// =====================================================================
//  Véhicules, trajets et barème kilométrique
// =====================================================================
$r->get('/vehicules', function (): void {
    Auth::exiger();
    $c     = contexte('vehicules');
    $exId  = (int) $c['exercice']['id'];
    $annee = (int) $c['exercice']['annee'];

    View::rendre('vehicules/index', $c + [
        'titre_page'  => 'Véhicules et trajets',
        'flash'       => flash(),
        'vehicules'   => Vehicule::lister($c['uid']),
        'trajets'     => Vehicule::trajets($c['uid'], $exId),
        'indemnites'  => Vehicule::indemnites($c['uid'], $exId, $annee),
        'bareme_ok'   => BaremeKm::existe($annee),
        // Les immobilisations viennent d'une extension : absente, la liste est vide.
        'immos'       => Plugin::estActif('immobilisations') ? \Immobilisations\Immobilisation::lister($c['uid'], ['actives' => true]) : [],
        'erreurs'     => [],
    ]);
});

$r->post('/vehicules/enregistrer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c  = contexte('vehicules');
    $id = (int) ($_POST['id'] ?? 0);

    $d = [
        'designation'       => mb_substr(trim((string) ($_POST['designation'] ?? '')), 0, 190),
        'immatriculation'   => mb_substr(trim((string) ($_POST['immatriculation'] ?? '')), 0, 20),
        'type_vehicule'     => in_array($_POST['type_vehicule'] ?? '', ['voiture','moto','cyclomoteur'], true)
                                ? $_POST['type_vehicule'] : 'voiture',
        'puissance_cv'      => max(1, min(99, (int) ($_POST['puissance_cv'] ?? 5))),
        'electrique'        => !empty($_POST['electrique']) ? 1 : 0,
        'immobilisation_id' => (int) ($_POST['immobilisation_id'] ?? 0) ?: null,
    ];

    if ($d['designation'] === '') {
        flash('La désignation du véhicule est obligatoire.', 'ko');
        redirige('/vehicules');
    }

    if ($id && Vehicule::trouver($c['uid'], $id)) {
        Vehicule::modifier($c['uid'], $id, $d);
        flash('Véhicule mis à jour.');
    } else {
        Vehicule::creer($c['uid'], $d);
        flash('Véhicule ajouté.');
    }
    redirige('/vehicules');
});

$r->post('/vehicules/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    Vehicule::supprimer(Auth::userId(), (int) $p['id']);
    flash('Véhicule supprimé, ainsi que ses trajets.');
    redirige('/vehicules');
});

$r->post('/trajets/creer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('vehicules');
    Exercice::exigerOuvert($c['exercice']);

    $vid  = (int) ($_POST['vehicule_id'] ?? 0);
    $date = Montant::lireDate((string) ($_POST['date_trajet'] ?? ''));
    $km   = Montant::lire((string) ($_POST['kilometres'] ?? ''));
    $motif = trim((string) ($_POST['motif'] ?? ''));

    if (Vehicule::trouver($c['uid'], $vid) === null) { flash('Véhicule inconnu.', 'ko'); redirige('/vehicules'); }
    if ($date === null)            { flash('Date de trajet invalide.', 'ko'); redirige('/vehicules'); }
    if ($motif === '')             { flash('Le motif du déplacement est obligatoire.', 'ko'); redirige('/vehicules'); }
    if ($km === null || $km <= 0)  { flash('Le kilométrage doit être supérieur à zéro.', 'ko'); redirige('/vehicules'); }

    Vehicule::ajouterTrajet($c['uid'], (int) $c['exercice']['id'], [
        'vehicule_id' => $vid, 'date_trajet' => $date, 'motif' => $motif, 'kilometres' => $km,
    ]);
    flash('Trajet enregistré.');
    redirige('/vehicules');
});

$r->post('/trajets/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    // Les trajets alimentent l'indemnité kilométrique, donc la case BR :
    // les retirer sur un exercice clos changerait une déclaration établie.
    $c = contexte('vehicules');
    Exercice::exigerOuvert($c['exercice']);
    Vehicule::supprimerTrajet($c['uid'], (int) $p['id']);
    redirige('/vehicules');
});

$r->post('/vehicules/option-bareme', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('vehicules');
    Exercice::exigerOuvert($c['exercice']);

    $actif = !empty($_POST['actif']) ? 1 : 0;
    Database::modifier('exercices', ['option_bareme_km' => $actif],
        'id = :i AND user_id = :u', ['i' => (int) $c['exercice']['id'], 'u' => $c['uid']]);

    flash($actif
        ? 'Option pour le barème activée : les frais réels de véhicule et l\'amortissement des véhicules ne sont plus déductibles cette année.'
        : 'Option levée : les frais réels de véhicule redeviennent déductibles.');
    redirige('/vehicules');
});

$r->get('/vehicules/bareme', function (): void {
    Auth::exiger();
    $c     = contexte('vehicules');
    $annee = (int) ($_GET['annee'] ?? $c['exercice']['annee']);

    View::rendre('vehicules/bareme', $c + [
        'titre_page' => 'Barème kilométrique',
        'flash'      => flash(),
        'annee'      => $annee,
        'lignes'     => BaremeKm::lignes($annee),
        'annees'     => BaremeKm::anneesDisponibles(),
    ]);
});

$r->post('/vehicules/bareme/dupliquer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $source = (int) ($_POST['source'] ?? 0);
    $cible  = (int) ($_POST['cible'] ?? 0);

    if ($cible < 2000 || $cible > 2100) { flash('Année cible invalide.', 'ko'); redirige('/vehicules/bareme'); }

    $n = BaremeKm::dupliquer($source, $cible);
    flash($n > 0
        ? "{$n} lignes reportées sur {$cible}. Vérifier chaque coefficient contre l'arrêté de l'année."
        : "Report impossible : l'année {$cible} a déjà un barème, ou l'année source n'en a pas.",
        $n > 0 ? 'ok' : 'ko');
    redirige('/vehicules/bareme?annee=' . $cible);
});

$r->post('/vehicules/bareme/enregistrer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $annee = (int) ($_POST['annee'] ?? 0);
    $n = 0;

    foreach ((array) ($_POST['coefficient'] ?? []) as $id => $coef) {
        // Un coefficient a trois ou quatre décimales : lireDecimal, pas lire.
        $c = Montant::lireDecimal((string) $coef);
        $k = Montant::lireDecimal((string) (($_POST['constante'][$id] ?? '0')));
        // DECIMAL(6,4) : au-delà, MySQL refuserait l'écriture.
        if ($c === null || $c < 0 || $c > 99 || $k === null || $k < 0 || $k > 999999) {
            continue;
        }
        BaremeKm::majCoefficient((int) $id, $c, $k);
        $n++;
    }
    flash("{$n} ligne(s) enregistrée(s).");
    redirige('/vehicules/bareme?annee=' . $annee);
});

// =====================================================================
//  Extensions
// =====================================================================
$r->get('/extensions', function (): void {
    Auth::exiger(); exigerAdmin();
    View::rendre('extensions/index', contexte('extensions') + [
        'titre_page' => 'Extensions',
        'flash'      => flash(),
        'plugins'    => Plugin::decouvrir(),
        'etats'      => Plugin::etats(),
        'erreurs_maj' => Plugin::erreursMigration(),
    ]);
});

$r->post('/extensions/{id:[a-z0-9_-]+}/activer', function (array $p): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $err = Plugin::activer((string) $p['id']);
    flash($err === '' ? 'Extension activée. Recharger la page pour voir ses entrées de menu.' : $err,
          $err === '' ? 'ok' : 'ko');
    redirige('/extensions');
});

$r->post('/extensions/{id:[a-z0-9_-]+}/desactiver', function (array $p): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    Plugin::desactiver((string) $p['id']);
    flash('Extension désactivée. Ses données sont conservées et seront retrouvées à la réactivation.');
    redirige('/extensions');
});

// =====================================================================
//  TVA : ce que l'on doit à l'État, période par période (assujettis)
// =====================================================================
$r->get('/tva', function (): void {
    Auth::exiger();
    $c = contexte('tva');
    if (!$c['tva']) {
        flash('La page TVA concerne les assujettis à la TVA : choisir ce régime dans Mon compte.', 'ko');
        redirige('/');
    }
    $exId = (int) $c['exercice']['id'];
    View::rendre('tva/index', $c + [
        'titre_page' => 'TVA',
        'flash'      => flash(),
        'tp'         => TvaPeriodes::calculer($c['uid'], $exId),
        'precedent'  => TvaPeriodes::creditPrecedent($c['uid'], $exId),
    ]);
});

$r->post('/tva/periodicite', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $err = TvaPeriodes::definirPeriodicite(Auth::userId(), (string) ($_POST['periodicite'] ?? ''));
    flash($err === '' ? 'Périodicité enregistrée.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/tva');
});

$r->post('/tva/credit', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('tva');
    $m = Montant::lire((string) ($_POST['credit'] ?? ''));
    $err = $m === null ? 'Le crédit de TVA doit être un nombre.' : TvaPeriodes::definirCreditInitial($c['uid'], (int) $c['exercice']['id'], $m);
    flash($err === '' ? 'Crédit de TVA reporté enregistré.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/tva');
});

$r->post('/tva/credit/reprendre', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('tva');
    $exId = (int) $c['exercice']['id'];
    $prec = TvaPeriodes::creditPrecedent($c['uid'], $exId);   // recalculé ici : on ne se fie pas à la page
    $err = $prec === null ? 'Aucun exercice précédent.' : TvaPeriodes::definirCreditInitial($c['uid'], $exId, $prec['credit_c'] / 100);
    flash($err === '' ? 'Crédit de l\'exercice ' . $prec['annee'] . ' repris : ' . View::montant($prec['credit_c'] / 100) . ' €.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/tva');
});

$r->get('/tva/export', function (): void {
    Auth::exiger();
    $c = contexte('tva');
    if (!$c['tva']) { redirige('/'); }
    Export::envoyer(
        Export::tvaCsv($c['uid'], (int) $c['exercice']['id']),
        sprintf('tva-%d.csv', $c['exercice']['annee']),
        'text/csv; charset=UTF-8'
    );
});

/** Ce que ExtensionZip doit savoir de cette installation pour juger une archive. */
function contexteExtensions(): array
{
    $installees = [];
    foreach (Plugin::decouvrir() as $id => $m) {
        $installees[$id] = (string) ($m['version'] ?? '');
    }
    return [
        'installees' => $installees,
        'core'       => (string) (MiseAJour::versionCourante() ?? ''),
        'reserves'   => array_keys(Plugin::REMPLACEES),
        'catalogues' => Boutique::toutes(),
    ];
}

function dossierSauvegardesExtensions(): string
{
    return dirname(Plugin::racine()) . '/storage/sauvegardes';
}

// =====================================================================
//  Import d'une extension depuis un zip : analyse, aperçu, confirmation
// =====================================================================
$r->post('/extensions/importer', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $f = $_FILES['archive'] ?? null;
    if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Aucun fichier reçu. Vérifier sa taille : le serveur limite les envois (upload_max_filesize).', 'ko');
        redirige('/extensions');
    }
    $a = ExtensionZip::analyser((string) $f['tmp_name'], (string) $f['name'], contexteExtensions());
    if (!$a['ok']) {
        flash((string) $a['erreur'], 'ko');
        redirige('/extensions');
    }
    if (!empty($_SESSION['ext_import']['chemin'])) {
        @unlink((string) $_SESSION['ext_import']['chemin']);   // une analyse en attente est remplacée
    }
    $_SESSION['ext_import'] = ['chemin' => $a['chemin'], 'sha256' => $a['sha256'], 'id' => $a['id']];
    redirige('/extensions/importer');
});

$r->get('/extensions/importer', function (): void {
    Auth::exiger(); exigerAdmin();
    $s = $_SESSION['ext_import'] ?? null;
    if (!is_array($s) || !is_file((string) $s['chemin'])) {
        flash('Aucune archive en attente : en choisir une.', 'ko');
        redirige('/extensions');
    }
    $a = ExtensionZip::apercu((string) $s['chemin'], 'archive.zip', contexteExtensions());
    if (!$a['ok'] || !hash_equals((string) $s['sha256'], (string) $a['sha256'])) {
        @unlink((string) $s['chemin']);
        unset($_SESSION['ext_import']);
        flash($a['ok'] ? 'Le fichier a changé depuis l\'analyse : recommencer.' : (string) $a['erreur'], 'ko');
        redirige('/extensions');
    }
    View::rendre('extensions/importer', contexte('extensions') + ['titre_page' => 'Importer une extension', 'flash' => flash(), 'apercu' => $a]);
});

$r->post('/extensions/importer/confirmer', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $s = $_SESSION['ext_import'] ?? null;
    if (!is_array($s) || !is_file((string) $s['chemin'])) {
        flash('Aucune archive en attente : en choisir une.', 'ko');
        redirige('/extensions');
    }
    if (empty($_POST['confiance'])) {
        flash('Cochez la case pour confirmer que vous faites confiance à la source de ce fichier.', 'ko');
        redirige('/extensions/importer');
    }
    // Le fichier est resté sur le disque entre l'aperçu et ici : on vérifie qu'il n'a pas bougé.
    if (!hash_equals((string) $s['sha256'], (string) hash_file('sha256', (string) $s['chemin']))) {
        @unlink((string) $s['chemin']);
        unset($_SESSION['ext_import']);
        flash('Le fichier a changé depuis l\'analyse : recommencer.', 'ko');
        redirige('/extensions');
    }
    $res = ExtensionZip::installer((string) $s['chemin'], Plugin::racine(), dossierSauvegardesExtensions(), contexteExtensions());
    unset($_SESSION['ext_import']);
    if (!$res['ok']) {
        flash((string) $res['erreur'], 'ko');
        redirige('/extensions');
    }
    $verbe = match ($res['action']) { 'mise_a_jour' => 'mise à jour', 'identique' => 'réinstallée', default => 'installée' };
    flash('Extension « ' . $res['id'] . ' » ' . $verbe . ' (v' . $res['version'] . ').'
        . ($res['action'] === 'installation' ? ' Il reste à l\'activer.' : ' Ses données sont conservées ; ses migrations s\'appliquent au prochain chargement.')
        . ($res['sauvegarde'] ? ' Ancienne version sauvegardée : ' . $res['sauvegarde'] . '.' : ''));
    redirige('/extensions');
});

$r->post('/extensions/importer/annuler', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    if (!empty($_SESSION['ext_import']['chemin'])) {
        @unlink((string) $_SESSION['ext_import']['chemin']);
    }
    unset($_SESSION['ext_import']);
    flash('Import annulé : rien n\'a été installé.');
    redirige('/extensions');
});

// =====================================================================
//  Boutique : catalogue embarqué et boutiques importées
// =====================================================================
$r->get('/boutique/image/{nom:[A-Za-z0-9._-]+}', function (array $p): void {
    Auth::exiger(); exigerAdmin();
    $f = Boutique::cheminImage((string) $p['nom']);
    if ($f === null) {
        http_response_code(404);
        echo 'introuvable';
        return;
    }
    $etag = '"' . md5($f . filemtime($f) . filesize($f)) . '"';
    // La session a déjà annoncé « Pragma: no-cache » et une date d'expiration dans le passé : on les retire, sans quoi
    // elles contredisent le Cache-Control ci-dessous et l'image serait rechargée à chaque affichage.
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . Boutique::typeImage($f));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }
    header('Content-Length: ' . filesize($f));
    readfile($f);
});

$r->get('/boutique', function (): void {
    Auth::exiger(); exigerAdmin();
    View::rendre('boutique/index', contexte('boutique') + [
        'titre_page'      => 'Boutique',
        'flash'           => flash(),
        'boutiques'       => Boutique::toutes(),
        'plugins'         => Plugin::decouvrir(),
        'etats'           => Plugin::etats(),
        'core'            => (string) (MiseAJour::versionCourante() ?? ''),
        'defaut_manquant' => Boutique::defaut() === null,
    ]);
});

$r->post('/boutique/importer', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $f = $_FILES['fichier'] ?? null;
    $adresse = trim((string) ($_POST['adresse'] ?? ''));
    if ($f && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $res = (int) $f['size'] > Boutique::TAILLE_MAX
            ? ['ok' => false, 'erreur' => 'Fichier trop gros pour un catalogue.', 'cle' => null, 'nb' => 0]
            : Boutique::importer((string) file_get_contents((string) $f['tmp_name']), null);
    } elseif ($adresse !== '') {
        [$brut, $err] = Boutique::telecharger($adresse);
        $res = $brut === null ? ['ok' => false, 'erreur' => $err, 'cle' => null, 'nb' => 0] : Boutique::importer($brut, $adresse);
    } else {
        flash('Indiquer une adresse, ou choisir un fichier .json.', 'ko');
        redirige('/boutique');
    }
    flash($res['ok'] ? 'Boutique « ' . $res['cle'] . ' » importée : ' . $res['nb'] . ' produit(s).' : (string) $res['erreur'], $res['ok'] ? 'ok' : 'ko');
    redirige('/boutique');
});

$r->post('/boutique/{cle:[a-z0-9_-]+}/actualiser', function (array $p): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $res = Boutique::actualiser((string) $p['cle']);
    flash($res['ok'] ? 'Boutique actualisée : ' . $res['nb'] . ' produit(s).' : (string) $res['erreur'], $res['ok'] ? 'ok' : 'ko');
    redirige('/boutique');
});

$r->post('/boutique/{cle:[a-z0-9_-]+}/supprimer', function (array $p): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    Boutique::supprimer((string) $p['cle']);
    flash('Boutique retirée. Les extensions déjà installées ne sont pas touchées.');
    redirige('/boutique');
});


// =====================================================================
//  Comptes bancaires
// =====================================================================
$r->get('/comptes', function (): void {
    Auth::exiger();
    $c = contexte('comptes');
    View::rendre('comptes/index', $c + [
        'titre_page'  => 'Comptes bancaires',
        'flash'       => flash(),
        'comptes'     => CompteBancaire::lister($c['uid']),
        'stats'       => CompteBancaire::statistiques($c['uid'], (int) $c['exercice']['id']),
        'sans_compte' => CompteBancaire::nbSansCompte($c['uid'], (int) $c['exercice']['id']),
        'ligne'       => null,
    ]);
});

$r->get('/comptes/{id:\d+}', function (array $p): void {
    Auth::exiger();
    $c = contexte('comptes');
    $ligne = CompteBancaire::trouver($c['uid'], (int) $p['id']);
    if ($ligne === null) { http_response_code(404); redirige('/comptes'); }

    View::rendre('comptes/index', $c + [
        'titre_page'  => $ligne['libelle'],
        'flash'       => flash(),
        'comptes'     => CompteBancaire::lister($c['uid']),
        'stats'       => CompteBancaire::statistiques($c['uid'], (int) $c['exercice']['id']),
        'sans_compte' => CompteBancaire::nbSansCompte($c['uid'], (int) $c['exercice']['id']),
        'ligne'       => $ligne,
    ]);
});

$r->post('/comptes/enregistrer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c  = contexte('comptes');
    $id = (int) ($_POST['id'] ?? 0);

    $d = [
        'libelle'  => mb_substr(trim((string) ($_POST['libelle'] ?? '')), 0, 120),
        'banque'   => mb_substr(trim((string) ($_POST['banque'] ?? '')), 0, 120),
        'iban_fin' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['iban_fin'] ?? '')) ?? '', -8),
        'nature'   => isset(CompteBancaire::NATURES[$_POST['nature'] ?? ''])
                        ? $_POST['nature'] : 'professionnel',
        'motifs'   => mb_substr(trim((string) ($_POST['motifs'] ?? '')), 0, 255),
    ];

    if ($d['libelle'] === '') {
        flash('Le libellé du compte est obligatoire.', 'ko');
        redirige('/comptes');
    }

    if ($id && CompteBancaire::trouver($c['uid'], $id)) {
        CompteBancaire::modifier($c['uid'], $id, $d);
        flash('Compte mis à jour.');
    } else {
        CompteBancaire::creer($c['uid'], $d);
        flash('Compte ajouté.');
    }
    redirige('/comptes');
});

$r->post('/comptes/{id:\d+}/basculer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    CompteBancaire::basculerActif(Auth::userId(), (int) $p['id']);
    redirige('/comptes');
});

$r->post('/comptes/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    CompteBancaire::supprimer(Auth::userId(), (int) $p['id']);
    flash('Compte supprimé. Les opérations importées restent, sans rattachement.');
    redirige('/comptes');
});

/** Rattache après coup les opérations sans compte, d'après leur libellé. */
$r->post('/comptes/rattacher', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('comptes');
    $exId = (int) $c['exercice']['id'];
    $n = 0;

    foreach (Database::tous(
        'SELECT id, compte FROM transactions_brutes
          WHERE user_id = :u AND exercice_id = :e AND compte_id IS NULL',
        ['u' => $c['uid'], 'e' => $exId]) as $tx) {

        $compteId = CompteBancaire::reconnaitre($c['uid'], $tx['compte']);
        if ($compteId !== null) {
            Database::modifier('transactions_brutes', ['compte_id' => $compteId],
                'id = :i AND user_id = :u', ['i' => (int) $tx['id'], 'u' => $c['uid']]);
            $n++;
        }
    }

    $paires = VirementInterne::detecter($c['uid'], $exId);
    flash(sprintf('%d opération(s) rattachée(s)%s.', $n,
        $paires > 0 ? sprintf(', %d virement(s) interne(s) repéré(s)', $paires) : ''));
    redirige('/comptes');
});

$r->post('/affectation/detecter-virements', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('triage');
    $n = VirementInterne::detecter($c['uid'], (int) $c['exercice']['id']);
    flash($n > 0
        ? sprintf('%d virement(s) interne(s) repéré(s), à confirmer.', $n)
        : 'Aucune paire trouvée. Vérifier que les opérations sont bien rattachées à des comptes distincts.');
    redirige('/affectation');
});


// =====================================================================
//  Nomenclature métier
// =====================================================================
$r->get('/categories', function (): void {
    Auth::exiger();
    $c = contexte('categories');
    View::rendre('categories/index', $c + [
        'titre_page' => 'Catégories',
        'flash'      => flash(),
        'recettes'   => CategorieMetier::lister($c['uid'], 'recette', false),
        'depenses'   => CategorieMetier::lister($c['uid'], 'depense', false),
        'postes_r'   => Poste2035::saisissables('recette'),
        'postes_d'   => Poste2035::saisissables('depense'),
        'erreurs'    => [],
    ]);
});

$r->post('/categories/creer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $erreurs = CategorieMetier::creer(Auth::userId(), $_POST);
    flash($erreurs ? $erreurs[0] : 'Catégorie ajoutée.', $erreurs ? 'ko' : 'ok');
    redirige('/categories');
});

$r->post('/categories/{id:\d+}/basculer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $err = CategorieMetier::basculerActif(Auth::userId(), (int) $p['id']);
    if ($err !== '') flash($err, 'ko');
    redirige('/categories');
});

$r->post('/categories/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $err = CategorieMetier::supprimer(Auth::userId(), (int) $p['id']);
    flash($err === '' ? 'Catégorie supprimée.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/categories');
});


// =====================================================================
//  Régularisations de fin d'exercice
// =====================================================================
$r->get('/regularisations', function (): void {
    Auth::exiger();
    $c = contexte('regularisations');
    $exId = (int) $c['exercice']['id'];

    View::rendre('regularisations/index', $c + [
        'titre_page' => 'Régularisations',
        'flash'      => flash(),
        'lignes'     => Regularisation::lister($c['uid'], $exId),
        'impact'     => Regularisation::impact($c['uid'], $exId, (int) $c['exercice']['annee']),
        'cat_dep'    => CategorieMetier::lister($c['uid'], 'depense'),
        'cat_rec'    => CategorieMetier::lister($c['uid'], 'recette'),
        'ligne'      => null,
        'erreurs'    => [],
    ]);
});

$r->get('/regularisations/{id:\d+}', function (array $p): void {
    Auth::exiger();
    $c = contexte('regularisations');
    $exId = (int) $c['exercice']['id'];
    $ligne = Regularisation::trouver($c['uid'], (int) $p['id']);
    if ($ligne === null) { http_response_code(404); redirige('/regularisations'); }

    View::rendre('regularisations/index', $c + [
        'titre_page' => 'Régularisation',
        'flash'      => flash(),
        'lignes'     => Regularisation::lister($c['uid'], $exId),
        'impact'     => Regularisation::impact($c['uid'], $exId, (int) $c['exercice']['annee']),
        'cat_dep'    => CategorieMetier::lister($c['uid'], 'depense'),
        'cat_rec'    => CategorieMetier::lister($c['uid'], 'recette'),
        'ligne'      => $ligne,
        'erreurs'    => [],
    ]);
});

$r->post('/regularisations/enregistrer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('regularisations');
    Exercice::exigerOuvert($c['exercice']);
    $id = (int) ($_POST['id'] ?? 0);

    if ($id && Regularisation::trouver($c['uid'], $id)) {
        $erreurs = Regularisation::modifier($c['uid'], $id, $_POST);
    } else {
        [$id, $erreurs] = Regularisation::creer($c['uid'], (int) $c['exercice']['id'], $_POST);
    }

    flash($erreurs ? $erreurs[0] : 'Régularisation enregistrée.', $erreurs ? 'ko' : 'ok');
    redirige('/regularisations');
});

$r->post('/regularisations/{id:\d+}/supprimer', function (array $p): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('regularisations');
    Exercice::exigerOuvert($c['exercice']);
    Regularisation::supprimer($c['uid'], (int) $p['id']);
    flash('Régularisation supprimée.');
    redirige('/regularisations');
});


// =====================================================================
//  Annexe 2035-E et registres imprimables
// =====================================================================
$r->get('/declaration/2035e', function (): void {
    Auth::exiger();
    $c    = contexte('2035');
    $calc = Declaration::calculer($c['uid'], (int) $c['exercice']['id']);

    View::rendre('declaration/2035e', $c + [
        'titre_page'   => 'Annexe 2035-E',
        'flash'        => flash(),
        'va'           => Declaration2035E::calculer($calc),
        'non_classes'  => Declaration2035E::postesNonClasses($calc),
        'resultat'     => $calc['resultat'],
    ]);
});

$r->get('/recettes/registre', function (): void {
    Auth::exiger();
    $c = contexte('recettes');
    Export::envoyer(
        RegistrePdf::livreRecettes(Auth::utilisateur(), $c['exercice'], $c['uid']),
        sprintf('livre-recettes-%d.pdf', $c['exercice']['annee']),
        'application/pdf'
    );
});

$r->get('/depenses/registre', function (): void {
    Auth::exiger();
    $c = contexte('depenses');
    Export::envoyer(
        RegistrePdf::registreDepenses(Auth::utilisateur(), $c['exercice'], $c['uid']),
        sprintf('registre-depenses-%d.pdf', $c['exercice']['annee']),
        'application/pdf'
    );
});


// =====================================================================
//  Journal inaltérable et contrôle d'intégrité
// =====================================================================
$r->get('/journal', function (): void {
    Auth::exiger();
    $c = contexte('journal');
    $tous = isset($_GET['tous']);

    View::rendre('journal/index', $c + [
        'titre_page' => 'Journal des écritures',
        'flash'      => flash(),
        'lignes'     => Journal::lister($c['uid'], $tous ? null : (int) $c['exercice']['id']),
        'controle'   => Journal::verifier($c['uid']),
        'nb_total'   => Journal::nb($c['uid']),
        'tous'       => $tous,
    ]);
});


// =====================================================================
//  Archives d'exercice
// =====================================================================
$r->get('/archives', function (): void {
    Auth::exiger();
    $c = contexte('archives');
    $exId = (int) $c['exercice']['id'];

    $archives = Archive::pourExercice($c['uid'], $exId);
    $controles = [];
    foreach ($archives as $a) {
        $controles[(int) $a['id']] = Archive::verifier($c['uid'], (int) $a['id']);
    }

    View::rendre('archives/index', $c + [
        'titre_page' => 'Archives',
        'flash'      => flash(),
        'archives'   => $archives,
        'controles'  => $controles,
    ]);
});

$r->get('/archives/{id:\d+}/telecharger', function (array $p): void {
    Auth::exiger();
    $c = contexte('archives');
    $a = Archive::trouver($c['uid'], (int) $p['id']);
    if ($a === null) { http_response_code(404); redirige('/archives'); }

    // L'archive est servie telle qu'enregistrée : c'est ce contenu exact,
    // octet pour octet, dont l'empreinte a été calculée.
    Export::envoyer((string) $a['contenu'],
        sprintf('archive-%s.json', substr((string) $a['empreinte'], 0, 12)),
        'application/json; charset=UTF-8');
});


// =====================================================================
//  Mise à jour depuis l'interface
// =====================================================================
$r->get('/mise-a-jour', function (): void {
    Auth::exiger(); exigerAdmin();
    View::rendre('maj/index', contexte('mise-a-jour') + [
        'titre_page'  => 'Mise à jour',
        'flash'       => flash(),
        'version'     => MiseAJour::versionCourante(),
        'extension'   => MiseAJour::extensionDisponible(),
        'sauvegardes' => MiseAJour::sauvegardesExistantes(),
        'en_attente'  => MiseAJour::migrationsEnAttente(),
        'apercu'      => $_SESSION['maj_apercu'] ?? null,
    ]);
});

$r->post('/mise-a-jour/analyser', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();

    $f = $_FILES['archive'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('Aucun fichier reçu, ou envoi trop volumineux pour la configuration du serveur.', 'ko');
        redirige('/mise-a-jour');
    }

    $r2 = MiseAJour::analyser($f['tmp_name'], (string) $f['name']);
    if (!$r2['ok']) {
        flash($r2['erreur'], 'ko');
        redirige('/mise-a-jour');
    }

    // L'archive est conservée hors de la requête, le temps de la confirmation ;
    // rien ne s'applique avant que l'administrateur n'ait validé cet aperçu.
    $_SESSION['maj_apercu'] = $r2;
    redirige('/mise-a-jour');
});

$r->post('/mise-a-jour/annuler', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $a = $_SESSION['maj_apercu'] ?? null;
    if ($a && !empty($a['chemin'])) {
        @unlink($a['chemin']);
    }
    unset($_SESSION['maj_apercu']);
    redirige('/mise-a-jour');
});

$r->post('/mise-a-jour/appliquer', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();

    $a = $_SESSION['maj_apercu'] ?? null;
    unset($_SESSION['maj_apercu']);
    if (!$a || empty($a['chemin'])) {
        flash('Aucune mise à jour en attente de confirmation.', 'ko');
        redirige('/mise-a-jour');
    }

    $r2 = MiseAJour::appliquer($a['chemin']);
    if (!$r2['ok']) {
        flash('Mise à jour interrompue : ' . $r2['erreur'], 'ko');
        redirige('/mise-a-jour');
    }

    // Fichiers remplacés. Les migrations passent dans la requête suivante : celle-ci
    // a déjà chargé des classes de l'ancienne version, la suivante n'aura que du neuf.
    $_SESSION['maj_a_finaliser'] = ['fichiers' => $r2['nb_fichiers'], 'sauvegarde' => $r2['sauvegarde']];
    redirige('/mise-a-jour/finaliser');
});

$r->get('/mise-a-jour/sauvegardes/{nom}/telecharger', function (array $p): void {
    Auth::exiger(); exigerAdmin();
    $chemin = MiseAJour::cheminSauvegarde((string) $p['nom']);
    if ($chemin === null) { http_response_code(404); redirige('/mise-a-jour'); }
    Export::envoyer((string) file_get_contents($chemin), basename($chemin), 'application/zip');
});

$r->get('/mise-a-jour/finaliser', function (): void {
    Auth::exiger(); exigerAdmin();

    // Atteignable seulement à la suite d'une application réussie, et idempotent :
    // appliquer des migrations déjà appliquées ne fait rien.
    $fait = $_SESSION['maj_a_finaliser'] ?? null;
    unset($_SESSION['maj_a_finaliser']);
    if (!$fait) {
        redirige('/mise-a-jour');
    }

    $m = MiseAJour::migrer();
    $debut = sprintf('%d fichier(s) remplacé(s). Sauvegarde : %s. ', $fait['fichiers'], $fait['sauvegarde'] ?? '—');
    if ($m['erreur'] !== null) {
        flash($debut . 'Mais ' . $m['erreur'] . ' Les fichiers sont à jour ; relancer « Appliquer les migrations » '
            . 'ci-dessous une fois la cause levée.', 'ko');
    } else {
        flash($debut . ($m['appliquees']
            ? count($m['appliquees']) . ' migration(s) appliquée(s) : ' . implode(', ', $m['appliquees']) . '.'
            : 'Aucune migration à appliquer.'));
    }
    redirige('/mise-a-jour');
});

$r->post('/mise-a-jour/migrer', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    $m = MiseAJour::migrer();
    if ($m['erreur'] !== null) {
        flash($m['erreur'], 'ko');
    } else {
        flash($m['appliquees']
            ? count($m['appliquees']) . ' migration(s) appliquée(s) : ' . implode(', ', $m['appliquees']) . '.'
            : 'La base de données est déjà à jour.');
    }
    redirige('/mise-a-jour');
});

$r->post('/mise-a-jour/sauvegarder', function (): void {
    Auth::exiger(); exigerAdmin(); Auth::exigerJeton();
    [$nom, $err] = MiseAJour::sauvegarder();
    flash($err === '' ? 'Sauvegarde créée : ' . $nom : $err, $err === '' ? 'ok' : 'ko');
    redirige('/mise-a-jour');
});


// =====================================================================
//  Contrôle des doublons
// =====================================================================
$r->get('/doublons', function (): void {
    Auth::exiger();
    $c = contexte('doublons');
    View::rendre('doublons/index', $c + [
        'titre_page' => 'Doublons probables',
        'flash'      => flash(),
        'paires'     => Doublons::probables($c['uid'], 100),
    ]);
});

$r->post('/doublons/resoudre', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $err = Doublons::resoudre(Auth::userId(), (int) ($_POST['garder'] ?? 0), (int) ($_POST['ecarter'] ?? 0));
    flash($err === '' ? 'Doublon écarté. L\'autre opération est conservée.' : $err, $err === '' ? 'ok' : 'ko');
    redirige('/doublons');
});

$r->post('/doublons/ecarter', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    Doublons::ecarter(Auth::userId(), (int) ($_POST['a'] ?? 0), (int) ($_POST['b'] ?? 0));
    flash('Noté : ces deux opérations ne seront plus proposées comme doublon.');
    redirige('/doublons');
});


// =====================================================================
//  Provisions : cotisations et impôt, estimés par l'API de l'Urssaf
// =====================================================================
function donneesProvisions(array $c): array
{
    $exId   = (int) $c['exercice']['id'];
    $params = Provisions::parametres($c['uid']);
    $calc   = Declaration::calculer($c['uid'], $exId);
    $base   = Provisions::base($calc, $c['exercice'], $params['mode'], $params, new \DateTimeImmutable('today'));
    $ok     = Provisions::derniere($c['uid'], $exId, 'ok');
    $der    = Provisions::derniere($c['uid'], $exId);
    return [
        'titre_page'    => 'Provisions',
        'flash'         => flash(),
        'urssaf_actif'  => (bool) \Bnc\Config::get('urssaf.actif', true),
        'params'        => $params,
        'base'          => $base,
        'estimation'    => $ok,
        'derniere'      => $der,
        'chiffres'      => $ok ? Provisions::chiffres($ok['resultat']['valeurs'], $ok['entree']) : null,
        'perimee'       => $ok ? Provisions::perimee($ok, $base) : false,
        'historique'    => Provisions::historique($c['uid'], $exId),
        'erreurs'       => [],
        'saisie'        => [],
        'debut_activite' => (Auth::utilisateur()['date_debut_activite'] ?? null) ?: null,
    ];
}

$r->get('/provisions', function (): void {
    Auth::exiger();
    $c = contexte('provisions');
    View::rendre('provisions/index', $c + donneesProvisions($c));
});

$r->post('/provisions/estimer', function (): void {
    Auth::exiger(); Auth::exigerJeton();
    $c = contexte('provisions');
    if (!\Bnc\Config::get('urssaf.actif', true)) {
        flash('Les estimations par l\'Urssaf sont désactivées sur cette installation.', 'ko');
        redirige('/provisions');
    }

    [$params, $erreurs] = Provisions::valider($_POST);
    if ($erreurs) {
        // On réaffiche ce que la personne a saisi, pas ce qui est en base.
        View::rendre('provisions/index', $c + ['erreurs' => $erreurs, 'saisie' => $_POST] + donneesProvisions($c));
        return;
    }
    Provisions::enregistrerParametres($c['uid'], $params);

    $calc = Declaration::calculer($c['uid'], (int) $c['exercice']['id']);
    $res = Provisions::estimer(
        $c['uid'], $c['exercice'], $calc, $params,
        (Auth::utilisateur()['date_debut_activite'] ?? null) ?: null,
        new \DateTimeImmutable('today')
    );
    flash($res['message'], $res['ok'] ? 'ok' : 'ko');
    redirige('/provisions');
});

// Les extensions ajoutent leurs routes après celles du cœur : une extension
// ne peut donc pas détourner une adresse du cœur.
Plugin::enregistrerRoutes($r);

$r->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ?? '/');
