@php
    $classes = match ($article->status) {
        'published' => 'bg-green-100 text-green-800',
        'error' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp
<span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {{ $classes }}">{{ $article->statusLabel() }}</span>
@if($article->status === 'published' && $article->hasUnpublishedChanges())
    <span class="ml-1 inline-block whitespace-nowrap rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Cambios sin publicar</span>
@endif
@if(\App\Services\PublishDispatcher::isBusy($article))
    <span class="ml-1 inline-block whitespace-nowrap rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">{{ $article->lastDeployment->status === 'queued' ? 'En cola' : 'Publicando…' }}</span>
@endif
@if(isset(\App\Models\Article::REMOTE_LABELS[$article->remote_state]))
    <span class="ml-1 inline-block whitespace-nowrap rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800" title="Comprobado el {{ $article->remote_checked_at?->format('d/m/Y H:i') }}">{{ \App\Models\Article::REMOTE_LABELS[$article->remote_state] }}</span>
@endif
