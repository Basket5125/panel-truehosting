@props(['value' => null])

@if (is_null($value))
    {{-- Nothing to compare against: the previous period had no activity. --}}
    <span class="th-delta is-flat">{{ __('no earlier data') }}</span>
@elseif ($value > 0)
    <span class="th-delta is-up"><i class="fas fa-arrow-up"></i>{{ $value }}%</span>
@elseif ($value < 0)
    <span class="th-delta is-down"><i class="fas fa-arrow-down"></i>{{ abs($value) }}%</span>
@else
    <span class="th-delta is-flat">{{ __('no change') }}</span>
@endif
