{{--
    Compte rendu d'un import, à partir du message flash laissé par le contrôleur.

    Variables attendues :
      $key  clé du message flash ('import_questions' ou 'import_students')
--}}
@php($report = session($key))

@if($report)
<div class="mt-4 rounded-xl border px-4 py-3 text-sm {{ $report['errors'] === [] ? 'bg-emerald-50 border-emerald-200/70 text-emerald-800' : 'bg-amber-50 border-amber-200/70 text-amber-900' }}" role="status">
    <p class="font-medium">{{ $report['summary'] }}</p>

    @if($report['errors'] !== [])
    <ul class="mt-2 space-y-1 text-xs">
        @foreach($report['errors'] as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
    @endif

    @if(($report['hidden'] ?? 0) > 0)
    <p class="mt-2 text-xs">… et {{ $report['hidden'] }} autre(s) ligne(s) à corriger.</p>
    @endif
</div>
@endif
