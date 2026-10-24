<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @php
        $website_settings = app(App\Settings\WebsiteSettings::class);
    @endphp

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#065bf1">
    <meta content="{{ $website_settings->seo_title }}" property="og:title">
    <meta content="{{ $website_settings->seo_description }}" property="og:description">
    <meta
        content='{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('logo.png') ? asset('storage/logo.png') : asset('images/ctrlpanel_logo.png') }}'
        property="og:image">

    <title>{{ config('app.name', 'TrueHosting') }}</title>
    <link rel="icon"
          href="{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('favicon.ico') ? asset('storage/favicon.ico') : asset('favicon.ico') }}"
          type="image/x-icon">

    <script src="{{ asset('js/app.js') }}" defer></script>

    <link rel="preload" href="{{ asset('plugins/fontawesome-free/css/all.min.css') }}" as="style"
          onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="{{ asset('plugins/fontawesome-free/css/all.min.css') }}">
    </noscript>

    @php
        $recaptchaVersion = app(App\Settings\GeneralSettings::class)->recaptcha_version;
    @endphp
    @if ($recaptchaVersion)
        @switch($recaptchaVersion)
            @case("v2")
                {!! htmlScriptTagJsApi() !!}
                @break
            @case("v3")
                {!! RecaptchaV3::initJs() !!}
                @break
        @endswitch
    @endif

    @vite('themes/default/sass/app.scss')
    {{-- The version keeps a browser from serving a stale skin after a deploy. --}}
    @php
        $themeFile = public_path('themes/TrueHosting/theme.css');
        $themeVersion = is_file($themeFile) ? filemtime($themeFile) : 1;
    @endphp
    <link rel="stylesheet" href="{{ asset('themes/TrueHosting/theme.css') }}?v={{ $themeVersion }}">
</head>

<body class="th-body dark-mode">

@yield('content')

<script>
    // Signing in waits on the network, and a dead-looking button invites a
    // second click. Mark the submit as busy until the page actually leaves.
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form[data-busy]');
        if (!form || e.defaultPrevented) return;

        const button = form.querySelector('[type="submit"]');
        if (!button || button.dataset.busy === '1') return;

        button.dataset.idleHtml = button.innerHTML;
        button.dataset.busy = '1';
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>'
            + '<span>' + (form.dataset.busy || '') + '</span>';
    });

    // A bfcache restore hands back the page with the button still spinning.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-busy] [type="submit"][data-busy="1"]').forEach(function (button) {
            button.disabled = false;
            delete button.dataset.busy;
            if (button.dataset.idleHtml) button.innerHTML = button.dataset.idleHtml;
        });
    });
</script>

<script src="{{ asset('plugins/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script>
    @if (Session::has('error'))
        Swal.fire({
            icon: 'error',
            title: 'Oops...',
            html: '{{ Session::get('error') }}',
        })
    @endif
    @if (Session::has('success'))
        Swal.fire({
            icon: 'success',
            title: '{{ Session::get('success') }}',
            position: 'top-end',
            showConfirmButton: false,
            toast: true,
            timer: 3200,
            timerProgressBar: true,
        })
    @endif
</script>
</body>

</html>
