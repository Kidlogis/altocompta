<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Routeur minimal. Segments dynamiques via {nom}.
 */
final class Router
{
    private array $routes = [];

    public function get(string $motif, callable $action): void  { $this->ajouter('GET', $motif, $action); }
    public function post(string $motif, callable $action): void { $this->ajouter('POST', $motif, $action); }

    private function ajouter(string $methode, string $motif, callable $action): void
    {
        // {nom} accepte tout segment ; {nom:motif} le contraint.
        // Contraindre les identifiants à \d+ évite qu'une route littérale
        // comme /affectation/valider-suggestions soit avalée par /affectation/{id}.
        $regex = preg_replace_callback(
            '#\{([a-z_]+)(?::([^}]+))?\}#',
            static fn(array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $motif
        );
        $this->routes[] = [
            'methode' => $methode,
            'regex'   => '#^' . $regex . '$#',
            'action'  => $action,
        ];
    }

    public function dispatch(string $methode, string $chemin): void
    {
        $chemin = '/' . trim(parse_url($chemin, PHP_URL_PATH) ?? '/', '/');

        foreach ($this->routes as $r) {
            if ($r['methode'] !== $methode || !preg_match($r['regex'], $chemin, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            ($r['action'])($params);
            return;
        }

        http_response_code(404);
        View::rendre('erreur', [
            'titre_page' => 'Page introuvable',
            'titre'      => 'Page introuvable',
            'message'    => "L'adresse « {$chemin} » ne correspond à aucune page.",
        // Le layout applicatif suppose une session ouverte : hors connexion, on rend la page seule.
        ], Auth::connecte() ? 'layout/base' : 'layout/nu');
    }
}
