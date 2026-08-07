@props(['content' => null])

{{-- A tooltip only when there is something to say.

     Blade compiles component tags structurally, so the obvious
     `@if (...)<flux:tooltip>@endif ... @if (...)</flux:tooltip>@endif`
     silently breaks everything between the two halves. This wrapper keeps
     the tags balanced, so a card can gain a tooltip conditionally without
     its markup being duplicated in both branches. --}}
@if ($content)
    <flux:tooltip :content="$content">{{ $slot }}</flux:tooltip>
@else
    {{ $slot }}
@endif
