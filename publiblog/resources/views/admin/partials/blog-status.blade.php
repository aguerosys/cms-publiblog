@php
    $classes = match ($blog->status) {
        'active' => 'bg-green-100 text-green-800',
        'connection_error', 'hostkey_changed' => 'bg-red-100 text-red-800',
        default => 'bg-yellow-100 text-yellow-800',
    };
@endphp
<span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {{ $classes }}">{{ $blog->statusLabel() }}</span>
