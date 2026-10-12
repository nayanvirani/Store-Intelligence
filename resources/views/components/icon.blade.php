@props(['name'])
<svg {{ $attributes->merge(['class' => 'i']) }} aria-hidden="true" focusable="false"><use href="#i-{{ $name }}"></use></svg>
