{{-- Un texte d'évaluation, mis en forme : paragraphes et listes.

     Servent ce fragment : l'énoncé, le guide de correction, la copie rendue par
     l'étudiant et l'appréciation du correcteur — la même règle, les mêmes
     repères, une seule mise en forme pour tous les écrans. Le texte vient de la
     base (fichier importé, saisie à
     la main, réponse du candidat) : App\Support\QuestionText l'échappe fragment
     par fragment et n'ouvre que les balises de la mise en forme (paragraphes,
     listes). Aucun HTML du texte ne traverse la vue — un texte ne peut donc pas
     injecter de balise, même écrit par un candidat.

     Variables attendues : $text, $prefix (numéro de la question, facultatif),
     $class (classes du conteneur, facultatif). --}}
@php($prefix = $prefix ?? '')
@php($class = $class ?? '')
<div class="{{ $class }}">{!! \App\Support\QuestionText::html($text, $prefix) !!}</div>
