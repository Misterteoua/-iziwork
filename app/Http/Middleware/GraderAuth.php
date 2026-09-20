<?php

namespace App\Http\Middleware;

use App\Models\Grader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'accès d'un correcteur externe.
 *
 * Jamais la même clé de session que l'administration (`grader` d'un côté,
 * `admin_user` de l'autre), et jamais le même middleware : un correcteur ne peut
 * donc pas atteindre une page d'administration, même si une route était un jour
 * mal protégée. Ce n'est pas une garde de plus, c'est une garde séparée.
 *
 * L'échéance est vérifiée ici, à chaque requête : une mission dépassée ferme
 * l'accès sans qu'aucune tâche planifiée n'ait à tourner sur le serveur.
 */
class GraderAuth
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionGrader = $request->session()->get('grader');
        $graderId = is_array($sessionGrader) ? ($sessionGrader['id'] ?? null) : null;

        if (! is_numeric($graderId)) {
            return $this->refuse($request, 'Ouvrez le lien personnel qui vous a été transmis pour accéder à vos copies.');
        }

        $grader = Grader::find((int) $graderId);

        if ($grader === null) {
            $request->session()->forget('grader');

            return $this->refuse($request, 'Ouvrez le lien personnel qui vous a été transmis pour accéder à vos copies.');
        }

        if ($grader->isExpired()) {
            $request->session()->forget('grader');

            return $this->refuse($request, 'Votre délai de correction est dépassé : demandez la prolongation à l’organisation.');
        }

        // Le correcteur courant est partagé avec les vues par la requête : les
        // gabarits n'ont pas à relire la session pour savoir qui corrige.
        $request->attributes->set('grader', $grader);
        $request->setUserResolver(fn () => $grader);

        return $next($request);
    }

    private function refuse(Request $request, string $message): Response
    {
        return redirect()->route('correction.entry')->with('error', $message);
    }
}
