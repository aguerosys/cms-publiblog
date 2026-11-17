@php $d = $entry->details ?? []; @endphp
@if(! empty($d['changes']))
    <ul class="mt-1 space-y-0.5 text-xs text-gray-600">
        @foreach($d['changes'] as $field => $change)
            <li>
                <span class="font-medium">{{ ucfirst($field) }}:</span>
                @if(is_array($change))
                    <span class="text-gray-400 line-through">{{ $change[0] !== '' ? $change[0] : '(vacío)' }}</span>
                    → <span>{{ $change[1] !== '' ? $change[1] : '(vacío)' }}</span>
                @else
                    {{ $change }}
                @endif
            </li>
        @endforeach
    </ul>
@else
    <p class="mt-1 text-xs text-gray-500">
        @isset($d['fields']) Campos: {{ implode(', ', $d['fields']) }}. @endisset
        @isset($d['blogs']) Blogs: {{ is_array($d['blogs']) && isset($d['blogs']['antes']) ? implode(', ', $d['blogs']['antes']).' → '.implode(', ', $d['blogs']['después']) : implode(', ', (array) $d['blogs']) }}. @endisset
        @isset($d['error']) {{ $d['error'] }} @endisset
        @isset($d['articles']) {{ $d['articles'] }} artículos @isset($d['failed']) ({{ $d['failed'] }} con error)@endisset, {{ $d['files'] ?? 0 }} archivos. @endisset
        @isset($d['code']) Código: {{ $d['code'] }}. @endisset
        @isset($d['fingerprint']) {{ $d['fingerprint'] }} @endisset
        @isset($d['role']) Rol: {{ $d['role'] === 'superadmin' ? 'superadmin' : 'usuario de blog' }}. @endisset
        @isset($d['diff']) {{ $d['diff'] }} @endisset
    </p>
@endif
