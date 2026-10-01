{{-- Un énoncé, mis en forme : paragraphes et listes.

     Le texte vient de la base (fichier importé ou saisie à la main) :
     App\Support\QuestionText l'échappe fragment par fragment et n'ouvre que les
     balises de la mise en forme (paragraphes, listes). Aucun HTML du texte ne
     traverse la vue — un énoncé ne peut donc pas injecter de balise.

     Variables attendues : $text, $prefix (numéro de la question, facultatif),
     $class (classes du conteneur, facultatif). --}}
@php($prefix = $prefix ?? '')
@php($class = $class ?? '')
<div class="{{ $class }}">{!! \App\Support\QuestionText::html($text, $prefix) !!}</div>
