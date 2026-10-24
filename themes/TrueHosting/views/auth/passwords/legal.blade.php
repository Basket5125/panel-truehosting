@php($website_settings = app(App\Settings\WebsiteSettings::class))

@if ($website_settings->show_imprint || $website_settings->show_privacy || $website_settings->show_tos)
    <div class="text-center mt-4" style="font-size:.78rem">
        @if ($website_settings->show_imprint)
            <a target="_blank" href="{{ route('terms', 'imprint') }}">{{ __('Imprint') }}</a>
        @endif
        @if ($website_settings->show_privacy)
            <a target="_blank" href="{{ route('terms', 'privacy') }}">{{ __('Privacy') }}</a>
        @endif
        @if ($website_settings->show_tos)
            <a target="_blank" href="{{ route('terms', 'tos') }}">{{ __('Terms of Service') }}</a>
        @endif
    </div>
@endif
