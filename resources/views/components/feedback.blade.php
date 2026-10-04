@props(['type' => 'info', 'message' => null, 'title' => null, 'id' => 'auth-feedback', 'focus' => false])
@php($variant = in_array($type, ['success', 'error', 'info'], true) ? $type : 'info')
<div id="{{ $id }}" class="feedback feedback--{{ $variant }}" role="{{ $variant === 'error' ? 'alert' : 'status' }}"
    @if($title) aria-labelledby="{{ $id }}-title" @endif @if($focus) tabindex="-1" data-error-summary @endif>
    <svg class="feedback__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9" />
        @if($variant === 'success')<path d="m7 12 3 3 7-7" />
        @elseif($variant === 'error')<path d="M12 7v6m0 3v1" />
        @else<path d="M12 11v6m0-10v1" />@endif
    </svg>
    <div class="feedback__content">
        @if($title)<h2 id="{{ $id }}-title">{{ $title }}</h2>@endif
        @if($message)<p>{{ $message }}</p>@endif
        {{ $slot }}
    </div>
</div>
