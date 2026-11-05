@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'required' => false, 'readonly' => false, 'placeholder' => null])

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-gray-700">
        {{ $label }} @if($required)<span class="text-red-500">*</span>@endif
    </label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $type === 'password' ? '' : old($name, $value) }}"
           @if($required) required @endif @if($readonly) readonly @endif
           @if($placeholder) placeholder="{{ $placeholder }}" @endif
           autocomplete="off"
           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 {{ $readonly ? 'bg-gray-100 text-gray-500' : '' }} @error($name) border-red-400 @enderror">
    @if($hint)<p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>@endif
    @error($name)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>
