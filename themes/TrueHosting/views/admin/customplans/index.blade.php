@extends('layouts.main')

@section('page-title', __('Configurable plans'))

@section('content')
    <div class="th-page-head d-flex flex-wrap align-items-end justify-content-between" style="gap:1rem">
        <div>
            <h2 class="th-page-title">{{ __('Configurable plans') }}</h2>
            <p class="th-page-sub">{{ __('Customers pick the resources; the price comes from your per-unit rates.') }}</p>
        </div>
        <a href="{{ route('admin.customplans.create') }}" class="th-btn th-btn-primary">
            <i class="fas fa-plus"></i>{{ __('Create new') }}
        </a>
    </div>

    <div class="th-card">
        <div class="th-card-body p-0">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Billing Period') }}</th>
                    <th class="text-right">{{ __('per vCPU') }}</th>
                    <th class="text-right">{{ __('per GB RAM') }}</th>
                    <th class="text-right">{{ __('per GB disk') }}</th>
                    <th class="text-right">{{ __('Nodes') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($plans as $plan)
                    <tr>
                        <td>
                            <strong>{{ $plan->name }}</strong>
                            @if ($plan->description)
                                <span class="d-block text-muted" style="font-size:.76rem">{{ $plan->description }}</span>
                            @endif
                        </td>
                        <td>{{ $plan->billing_period }}</td>
                        <td class="text-right">{{ Currency::formatForDisplay($plan->price_cpu) }}</td>
                        <td class="text-right">{{ Currency::formatForDisplay($plan->price_memory) }}</td>
                        <td class="text-right">{{ Currency::formatForDisplay($plan->price_disk) }}</td>
                        <td class="text-right">{{ $plan->nodes_count }}</td>
                        <td>
                            @if ($plan->disabled)
                                <span class="th-status">{{ __('Disabled') }}</span>
                            @else
                                <span class="th-status is-online">{{ __('Active') }}</span>
                            @endif
                        </td>
                        <td class="text-right" style="white-space:nowrap">
                            <a href="{{ route('admin.customplans.edit', $plan->id) }}"
                               class="th-btn th-btn-ghost th-btn-sm">
                                <i class="fas fa-pen"></i>
                            </a>
                            <form method="POST" action="{{ route('admin.customplans.destroy', $plan->id) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('{{ __('Are you sure you wish to delete?') }}')">
                                @csrf
                                @method('DELETE')
                                <button class="th-btn th-btn-ghost th-btn-sm" style="color:#f87171" type="submit">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted">{{ __('No plans yet.') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
