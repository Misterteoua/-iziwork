<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Contrôle permanent des scripts écrits en ligne dans les vues Blade.
 *
 * Un script en ligne est le seul endroit du projet où une faute ne se voit
 * nulle part : PHP ne le lit pas, un navigateur le refuse en silence, et la page
 * perd d'un coup tout ce qui était piloté par JavaScript. Deux fautes sont
 * possibles, et les deux sont attrapées ici :
 *
 *   - une faute de syntaxe, qui empêche le script entier de s'exécuter ;
 *   - une collision de noms entre deux scripts d'une même page. Les balises
 *     <script> d'une page partagent le même espace global : un « const » déclaré
 *     deux fois fait échouer **tout** le script fautif, à la ligne de sa
 *     déclaration, sans que rien d'autre ne le signale.
 *
 * Le contrôle est fait par Node (déjà présent : c'est lui qui construit les
 * feuilles de style du projet), sur le code réellement analysable — les
 * directives Blade, les commentaires et les expressions rendues par le serveur
 * sont retirés d'abord, sinon on contrôlerait du PHP mêlé à du JavaScript.
 *
 * Ce qui n'est pas vérifié : le comportement, les noms de variables d'échelle
 * de fonction, et ce qu'un script fait à la page. Node lit la syntaxe, rien de
 * plus.
 *
 * Ce contrôle ne dépend d'aucune vérification manuelle : il tourne avec la
 * suite de tests, et échoue en nommant le fichier, la ligne et le motif.
 */
class BladeInlineScriptsTest extends TestCase
{
    /**
     * Les directives Blade admises dans un script.
     *
     * La liste est fermée, et c'est volontaire : un « @ » qui n'y figure pas est
     * laissé tel quel — une adresse électronique, une requête @media, une
     * décoration quelconque. Consommer tout ce qui suit un « @ » arracherait des
     * morceaux de JavaScript valide et ferait échouer le contrôle à tort.
     */
    private const BLADE_DIRECTIVES = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
        'isset', 'endisset', 'empty', 'endempty',
        'for', 'endfor', 'foreach', 'endforeach', 'forelse', 'endforelse',
        'while', 'endwhile', 'switch', 'case', 'break', 'default', 'endswitch', 'continue',
        'php', 'endphp', 'json', 'js', 'class', 'style',
        'checked', 'selected', 'disabled', 'required', 'readonly',
        'csrf', 'method', 'include', 'includeif', 'includewhen', 'includeunless', 'each',
        'extends', 'section', 'endsection', 'show', 'stop', 'yield', 'props', 'aware',
        'error', 'enderror', 'once', 'endonce', 'push', 'endpush', 'prepend', 'endprepend',
        'can', 'cannot', 'canany', 'lang', 'choice', 'env', 'production', 'guest', 'auth',
        'session', 'endguest', 'endenv', 'endproduction', 'endauth',
    ];

    public function test_les_scripts_en_ligne_des_vues_sont_syntaxiquement_valides(): void
    {
        $units = [];
        $raw = 0;
        $cleaned = 0;

        foreach ($this->bladeViews() as $path) {
            foreach ($this->scriptBlocks($path) as $index => $block) {
                $units[] = [
                    'name' => $this->relative($path).' — script '.($index + 1).' (ligne '.$block['line'].')',
                    'code' => $block['code'],
                ];

                $raw += $block['raw'];
                $cleaned += strlen(trim($block['code']));
            }
        }

        // Un contrôle qui ne trouverait rien à lire passerait sans rien vérifier :
        // le nombre est donc affirmé, et il bouge avec le projet.
        $this->assertGreaterThanOrEqual(10, count($units), 'Les scripts en ligne des vues n’ont pas été trouvés : le contrôle ne vérifie rien.');

        // Et ce qui a été lu doit être le script lui-même : un nettoyage trop
        // gourmand laisserait passer un fichier presque vide pour un fichier
        // valide. Les directives Blade ne pèsent jamais la moitié d'un script.
        $this->assertGreaterThanOrEqual(
            0.5 * $raw,
            $cleaned,
            'Le nettoyage des directives a vidé les scripts : le contrôle ne porterait plus sur rien.'
        );

        $this->assertSame([], $this->jsFailures($units), "Script(s) en ligne invalide(s).\n");
    }

    public function test_les_scripts_rassembles_sur_une_page_n_ont_pas_de_collision_de_noms(): void
    {
        $units = $this->pageUnits();

        $this->assertGreaterThanOrEqual(15, count($units), 'Les pages n’ont pas été reconnues : le contrôle des collisions ne vérifie rien.');

        $this->assertSame([], $this->jsFailures($units), "Collision(s) de noms entre les scripts d’une même page.\n");
    }

    /**
     * Le contrôle lui-même : s'il ne détectait rien, les deux tests ci-dessus
     * passeraient aussi, et le projet n'aurait qu'une fausse assurance.
     */
    public function test_le_controle_attrape_une_faute_de_syntaxe_et_une_collision_de_noms(): void
    {
        $failures = $this->jsFailures([
            ['name' => 'faute', 'code' => 'const reponse = ;'],
            ['name' => 'collision', 'code' => 'const total = 1; const total = 2;'],
            ['name' => 'retour-interdit', 'code' => 'return 1;'],
            ['name' => 'valide', 'code' => 'const total = 1; window.QuizGuard = { refresh: () => total };'],
        ]);

        $this->assertCount(3, $failures, 'Le contrôle laisse passer ce qu’il doit attraper : '.implode(' | ', $failures));

        $report = implode("\n", $failures);

        $this->assertStringContainsString('faute', $report);
        $this->assertStringContainsString('collision', $report);
        $this->assertStringContainsString("Identifier 'total' has already been declared", $report);
        $this->assertStringContainsString('retour-interdit', $report);
    }

    // ------------------------------------------------------------- Extraction

    /**
     * @return array<int, string>
     */
    private function bladeViews(): array
    {
        $paths = collect(File::allFiles(resource_path('views')))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn (string $path) => str_ends_with($path, '.blade.php'))
            ->sort()
            ->values()
            ->all();

        return $paths;
    }

    /**
     * Les blocs <script> d'une vue, dans l'ordre, avec leur ligne d'ouverture.
     *
     * Un script chargé depuis un fichier n'a pas de code à lire ici, et un script
     * qui n'est pas du JavaScript — un gabarit, des données — ne se lit pas comme
     * du JavaScript : les deux sont écartés.
     *
     * @return array<int, array{line: int, code: string, raw: int}>
     */
    private function scriptBlocks(string $path): array
    {
        $source = (string) file_get_contents($path);

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $source, $matches, PREG_OFFSET_CAPTURE);

        $blocks = [];

        foreach ($matches[0] as $index => [$full, $offset]) {
            $attributes = $matches[1][$index][0];
            $code = $matches[2][$index][0];

            if (stripos($attributes, 'src=') !== false) {
                continue;
            }

            if (preg_match('/type\s*=/i', $attributes) === 1
                && preg_match('/type\s*=\s*["\']?(text\/javascript|module|application\/javascript)/i', $attributes) !== 1) {
                continue;
            }

            $blocks[] = [
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'code' => $this->stripBlade($code),
                'raw' => strlen(trim($code)),
            ];
        }

        return $blocks;
    }

    /**
     * Le JavaScript d'une vue, débarrassé de ce que Blade y écrit.
     *
     * Sans ce nettoyage, on contrôlerait du PHP mêlé à du JavaScript : le
     * contrôle signalerait des fautes partout, et ne servirait plus à rien.
     */
    private function stripBlade(string $code): string
    {
        // {{-- commentaire --}}
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $code);

        // @php ... @endphp : du PHP, rien de plus.
        $code = (string) preg_replace('/@php\b.*?@endphp/s', '', $code);

        // Les directives (conditions, boucles, @json, @class…) disparaissent avec
        // leurs arguments : il ne reste que le JavaScript, que l'on contrôle.
        $code = $this->dropDirectives($code);

        // {{ $x }} et {!! $x !!} : une valeur rendue par le serveur. Remplacée par
        // un identifiant libre, seule forme qui reste valide partout — dans un
        // texte, un nombre, un appel ou un index.
        $code = (string) preg_replace('/\{!!.*?!!\}/s', 'x', $code);
        $code = (string) preg_replace('/\{\{.*?\}\}/s', 'x', $code);

        return $code;
    }

    private function dropDirectives(string $code): string
    {
        $out = '';
        $length = strlen($code);

        for ($i = 0; $i < $length; $i++) {
            if ($code[$i] !== '@' || $i + 1 >= $length || preg_match('/[A-Za-z_]/', $code[$i + 1]) !== 1) {
                $out .= $code[$i];

                continue;
            }

            $name = '';
            $cursor = $i + 1;

            while ($cursor < $length && preg_match('/[A-Za-z_]/', $code[$cursor]) === 1) {
                $name .= $code[$cursor];
                $cursor++;
            }

            if (! in_array(strtolower($name), self::BLADE_DIRECTIVES, true)) {
                // Un « @ » qui n'est pas une directive : on le garde tel quel.
                $out .= '@';

                continue;
            }

            while ($cursor < $length && ctype_space($code[$cursor])) {
                $cursor++;
            }

            if ($cursor < $length && $code[$cursor] === '(') {
                $depth = 0;
                $quote = null;

                for (; $cursor < $length; $cursor++) {
                    $char = $code[$cursor];

                    if ($quote !== null) {
                        if ($char === '\\') {
                            $cursor++;
                        } elseif ($char === $quote) {
                            $quote = null;
                        }

                        continue;
                    }

                    if ($char === '"' || $char === "'") {
                        $quote = $char;
                    } elseif ($char === '(') {
                        $depth++;
                    } elseif ($char === ')') {
                        $depth--;

                        if ($depth === 0) {
                            $cursor++;
                            break;
                        }
                    }
                }
            }

            $i = $cursor - 1;
        }

        return $out;
    }

    // ------------------------------------------------------------ Composition

    /**
     * Les scripts qu'une page rassemble réellement, mis bout à bout.
     *
     * Une page, ici, c'est la mise en page, la vue qui l'étend, et tout ce qu'elles
     * incluent — récursivement : c'est le seul ensemble dont les scripts partagent
     * le même espace de noms global. Deux pages différentes ne se rencontrent
     * jamais ; les confondre signalerait des collisions qui n'existent pas.
     *
     * @return array<int, array{name: string, code: string}>
     */
    private function pageUnits(): array
    {
        $layout = resource_path('views/layouts/app.blade.php');
        $units = [];

        foreach ($this->bladeViews() as $path) {
            $source = (string) file_get_contents($path);

            // Seules les vues qui étendent la mise en page sont des pages ; un
            // fragment est contrôlé par chacune des pages qui l'incluent.
            if (! str_contains($source, '@extends(')) {
                continue;
            }

            // La mise en page d'abord : ses scripts sont sur toutes les pages, et
            // c'est avec eux que ceux de la vue peuvent entrer en collision.
            $files = array_merge($this->composedViews($layout), $this->composedViews($path));

            $code = '';
            $read = [];

            foreach ($files as $file) {
                // Un fichier inclus deux fois n'est pas deux scripts : l'inclure
                // deux fois ici ferait croire à une collision avec lui-même.
                $key = realpath($file) ?: $file;

                if (isset($read[$key])) {
                    continue;
                }

                $read[$key] = true;

                foreach ($this->scriptBlocks($file) as $block) {
                    $code .= "\n".$block['code']."\n";
                }
            }

            $units[] = [
                'name' => $this->relative($layout).' + '.$this->relative($path),
                'code' => $code,
            ];
        }

        return $units;
    }

    /**
     * Une vue et tout ce qu'elle inclut, dans l'ordre de lecture.
     *
     * @param  array<string, bool>  $seen
     * @return array<int, string>
     */
    private function composedViews(string $path, array $seen = []): array
    {
        $key = realpath($path) ?: $path;

        // Un fragment qui s'inclut lui-même, en boucle, ne doit pas faire tourner
        // le contrôle sans fin.
        if (isset($seen[$key]) || count($seen) > 40) {
            return [];
        }

        $seen[$key] = true;

        $files = [$path];
        $source = (string) file_get_contents($path);

        // @include('a.b'), @includeIf, @includeWhen, @includeUnless : le premier
        // argument est le nom de la vue, en notation pointée. Un nom calculé
        // (@include($vue)) ne se résout pas ici — il est ignoré.
        preg_match_all('/@include(?:if|when|unless)?\(\s*\'([\w.\-]+)\'/', $source, $matches);

        foreach (array_unique($matches[1]) as $name) {
            $included = resource_path('views/'.str_replace('.', '/', $name).'.blade.php');

            if (! is_file($included)) {
                continue;
            }

            foreach ($this->composedViews($included, $seen) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    // ----------------------------------------------------------------- Contrôle

    /**
     * Les unités refusées par Node, avec le motif du refus.
     *
     * Node interprète chaque unité comme une balise <script> : une faute de
     * syntaxe, un « return » hors fonction ou une déclaration lexicale en double
     * sont refusés, et rien n'est exécuté.
     *
     * @param  array<int, array{name: string, code: string}>  $units
     * @return array<int, string>
     */
    private function jsFailures(array $units): array
    {
        $program = <<<'JS'
        const vm = require('node:vm');
        let input = '';
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => { input += chunk; });
        process.stdin.on('end', () => {
            const failures = [];
            for (const unit of JSON.parse(input)) {
                try {
                    new vm.Script(unit.code, { filename: unit.name });
                } catch (error) {
                    failures.push(unit.name + ' → ' + error.name + ': ' + String(error.message).split('\n')[0]);
                }
            }
            process.stdout.write(JSON.stringify(failures));
        });
        JS;

        $result = Process::input((string) json_encode($units))
            ->timeout(120)
            ->run([$this->nodeBinary(), '-e', $program]);

        if (! $result->successful()) {
            $this->fail("Le contrôle des scripts n'a pas pu s'exécuter :\n".$result->errorOutput().$result->output());
        }

        $decoded = json_decode($result->output(), true);

        $this->assertIsArray($decoded, 'Le contrôle des scripts n’a rien renvoyé de lisible : '.$result->output());

        return $decoded;
    }

    /**
     * Node, ou l'aveu que le contrôle ne peut pas tourner.
     *
     * Il est déjà là pour construire les feuilles de style du projet ; s'il
     * manque, mieux vaut le dire que de laisser croire à un contrôle passé.
     */
    private function nodeBinary(): string
    {
        $result = Process::timeout(30)->run([PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node', '--version']);

        if (! $result->successful()) {
            $this->markTestSkipped('Node est introuvable : le contrôle des scripts en ligne est impossible sur cette machine.');
        }

        return PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
