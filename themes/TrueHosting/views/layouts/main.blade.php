<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @use('App\Constants\PermissionGroups')
    @php
        // Blade pairs PHP blocks by regex starting at the first opening
        // directive in the file, so this layout must not mix in the inline
        // single-expression form; keep every PHP section in block form.
        $website_settings = app(App\Settings\WebsiteSettings::class);
        $general_settings = app(App\Settings\GeneralSettings::class);
        $discord_settings = app(App\Settings\DiscordSettings::class);
        $ticket_enabled = app(App\Settings\TicketSettings::class)->enabled;
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

    <script src="{{ asset('plugins/alpinejs/3.12.0_cdn.min.js') }}" defer></script>

    <link rel="stylesheet" href="{{ asset('plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') }}">
    <link rel="stylesheet" href="{{ asset('plugins/summernote/summernote-bs4.min.css') }}">
    <link rel="stylesheet"
          href="{{ asset('plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css') }}">
    <link rel="stylesheet" href="{{ asset('plugins/select2/css/select2.min.css') }}">

    <link rel="preload" href="{{ asset('plugins/fontawesome-free/css/all.min.css') }}" as="style"
          onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
        <link rel="stylesheet" href="{{ asset('plugins/fontawesome-free/css/all.min.css') }}">
    </noscript>

    <script src="{{ asset('js/app.js') }}"></script>
    <script src="{{ asset('plugins/tinymce/js/tinymce/tinymce.min.js') }}"></script>

    {{-- Bootstrap/AdminLTE base: inherited views from the `default` theme still rely on it. --}}
    @vite('themes/default/sass/app.scss')

    {{-- TrueHosting skin. Must load last so it can override the base. --}}
    {{-- The version keeps a browser from serving a stale skin after a deploy. --}}
    @php
        $themeFile = public_path('themes/TrueHosting/theme.css');
        $themeVersion = is_file($themeFile) ? filemtime($themeFile) : 1;
    @endphp
    <link rel="stylesheet" href="{{ asset('themes/TrueHosting/theme.css') }}?v={{ $themeVersion }}">

    <style>
        #userDropdown.dropdown-toggle::after,
        #creditsDropdown.dropdown-toggle::after { display: none !important; }
    </style>
</head>

<body class="th-body dark-mode"
      x-data="{
          collapsed: localStorage.getItem('th_sidebar') === '1',
          mobile: false,
          toggle() {
              if (window.innerWidth < 992) { this.mobile = !this.mobile; return; }
              this.collapsed = !this.collapsed;
              localStorage.setItem('th_sidebar', this.collapsed ? '1' : '0');
          }
      }"
      :class="{ 'th-collapsed': collapsed }">

<div class="th-shell">

    {{-- ===================== SIDEBAR ===================== --}}
    <aside class="th-sidebar" :class="{ 'th-open': mobile }">

        <a href="{{ route('home') }}" class="th-brand">
            <img class="th-brand-logo"
                 src="{{ \Illuminate\Support\Facades\Storage::disk('public')->exists('icon.png') ? asset('storage/icon.png') : asset('images/ctrlpanel_logo.png') }}"
                 alt="{{ config('app.name') }}">
            <span class="th-brand-text">
                <span class="th-brand-name">{{ config('app.name', 'TrueHosting') }}</span>
                <span class="th-brand-sub">
                    {{ Request::routeIs('admin.*') ? __('Admin area') : __('Client area') }}
                </span>
            </span>
        </a>

        @php
            // Everything under /admin is the administration context; the client
            // navigation is hidden there and vice versa.
            $inAdmin = Request::routeIs('admin.*');

            $adminPermissions = array_merge(
                PermissionGroups::OVERVIEW_PERMISSIONS,
                PermissionGroups::TICKET_ADMIN_PERMISSIONS,
                PermissionGroups::TICKET_BLACKLIST_PERMISSIONS,
                PermissionGroups::ROLES_PERMISSIONS,
                PermissionGroups::SETTINGS_PERMISSIONS,
                PermissionGroups::API_PERMISSIONS,
                PermissionGroups::USERS_PERMISSIONS,
                PermissionGroups::SERVERS_PERMISSIONS,
                PermissionGroups::PRODUCTS_PERMISSIONS,
                PermissionGroups::STORE_PERMISSIONS,
                PermissionGroups::VOUCHERS_PERMISSIONS,
                PermissionGroups::PARTNERS_PERMISSIONS,
                PermissionGroups::COUPONS_PERMISSIONS,
                PermissionGroups::USEFUL_LINKS_PERMISSIONS,
                PermissionGroups::PAYMENTS_PERMISSIONS,
                PermissionGroups::LOGS_PERMISSIONS,
            );
            $canAdmin = Auth::user()->canAny($adminPermissions);

            $serverCount = Auth::user()->servers()->count();
            // Whether the operator offers a trial at all. Deliberately not
            // narrowed to this customer: somebody who has used theirs keeps
            // the entry and is told so on the page, rather than watching a
            // menu item vanish with no explanation.
            $trialOffered = app(\App\Services\TrialService::class)->offered();

            $hasCustomPlans = cache()->remember(
                'th.has_custom_plans',
                now()->addMinutes(5),
                fn () => \App\Models\CustomPlan::where('disabled', false)->exists()
            );
            $paymentCount = cache()->remember(
                'th.payment_count',
                now()->addMinutes(5),
                fn () => \App\Models\Payment::count()
            );

            // Land on the first admin page this user is actually allowed to open.
            $adminHome = null;
            foreach ([
                ['admin.analytics.index', PermissionGroups::PAYMENTS_PERMISSIONS],
                ['admin.overview.index', PermissionGroups::OVERVIEW_PERMISSIONS],
                ['admin.users.index', PermissionGroups::USERS_PERMISSIONS],
                ['admin.servers.index', PermissionGroups::SERVERS_PERMISSIONS],
                ['admin.settings.index', PermissionGroups::SETTINGS_PERMISSIONS],
                ['admin.ticket.index', PermissionGroups::TICKET_ADMIN_PERMISSIONS],
                ['admin.activitylogs.index', PermissionGroups::LOGS_PERMISSIONS],
            ] as [$routeName, $permissions]) {
                if (Auth::user()->canAny($permissions)) {
                    $adminHome = route($routeName);
                    break;
                }
            }
        @endphp

        <nav class="th-nav">
            @unless ($inAdmin)

            <a href="{{ route('home') }}" class="th-nav-link @if (Request::routeIs('home')) active @endif">
                <i class="th-nav-icon fas fa-tachometer-alt"></i>
                <span class="th-nav-label">{{ __('Dashboard') }}</span>
            </a>

            <a href="{{ route('servers.index') }}"
               class="th-nav-link @if (Request::routeIs('servers.*')) active @endif">
                <i class="th-nav-icon fas fa-server"></i>
                <span class="th-nav-label">{{ __('Servers') }}</span>
                <span class="th-nav-badge">{{ $serverCount }}/{{ Auth::user()->server_limit }}</span>
            </a>

            @if ($trialOffered)
                <a href="{{ route('trial.create') }}"
                   class="th-nav-link @if (Request::routeIs('trial.*')) active @endif">
                    <i class="th-nav-icon fas fa-flask"></i>
                    <span class="th-nav-label">{{ __('Free trial') }}</span>
                </a>
            @endif

            @if ($hasCustomPlans)
                <a href="{{ route('plans.index') }}"
                   class="th-nav-link @if (Request::routeIs('plans.*')) active @endif">
                    <i class="th-nav-icon fas fa-sliders-h"></i>
                    <span class="th-nav-label">{{ __('Build your server') }}</span>
                </a>
            @endif

            @if (config('app.env') == 'local' || $general_settings->store_enabled)
                <a href="{{ route('store.index') }}"
                   class="th-nav-link @if (Request::routeIs('store.*') || Request::routeIs('checkout')) active @endif">
                    <i class="th-nav-icon fas fa-shopping-cart"></i>
                    <span class="th-nav-label">{{ __('Store') }}</span>
                </a>
            @endif

            @if ($ticket_enabled)
                @canany(PermissionGroups::TICKET_PERMISSIONS)
                    <a href="{{ route('ticket.index') }}"
                       class="th-nav-link @if (Request::routeIs('ticket.*')) active @endif">
                        <i class="th-nav-icon fas fa-headset"></i>
                        <span class="th-nav-label">{{ __('Support Ticket') }}</span>
                    </a>
                @endcanany
            @endif

            <a href="{{ route('profile.index') }}"
               class="th-nav-link @if (Request::routeIs('profile.*')) active @endif">
                <i class="th-nav-icon fas fa-user"></i>
                <span class="th-nav-label">{{ __('Profile') }}</span>
            </a>

            @if ($useful_links->count())
                <div class="th-nav-group-title">{{ __('Useful Links') }}</div>
                @foreach ($useful_links as $link)
                    <a href="{{ $link->link }}" target="__blank" class="th-nav-link">
                        <i class="th-nav-icon {{ $link->icon }}"></i>
                        <span class="th-nav-label">{{ $link->title }}</span>
                        <i class="fas fa-external-link-alt th-nav-badge"
                           style="background:none;font-size:.62rem"></i>
                    </a>
                @endforeach
            @endif

            @endunless

            @if ($inAdmin)
            @canany(array_merge(
                PermissionGroups::OVERVIEW_PERMISSIONS,
                PermissionGroups::TICKET_ADMIN_PERMISSIONS,
                PermissionGroups::TICKET_BLACKLIST_PERMISSIONS,
                PermissionGroups::ROLES_PERMISSIONS,
                PermissionGroups::SETTINGS_PERMISSIONS,
                PermissionGroups::API_PERMISSIONS
            ))
                <div class="th-nav-group-title">{{ __('Administration') }}</div>
            @endcanany

            @canany(PermissionGroups::OVERVIEW_PERMISSIONS)
                <a href="{{ route('admin.overview.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.overview.*')) active @endif">
                    <i class="th-nav-icon fas fa-chart-line"></i>
                    <span class="th-nav-label">{{ __('Overview') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::PAYMENTS_PERMISSIONS)
                <a href="{{ route('admin.analytics.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.analytics.*')) active @endif">
                    <i class="th-nav-icon fas fa-chart-pie"></i>
                    <span class="th-nav-label">{{ __('Analytics') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::TICKET_ADMIN_PERMISSIONS)
                <a href="{{ route('admin.ticket.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.ticket.index')) active @endif">
                    <i class="th-nav-icon fas fa-ticket-alt"></i>
                    <span class="th-nav-label">{{ __('Ticket List') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::TICKET_BLACKLIST_PERMISSIONS)
                <a href="{{ route('admin.ticket.blacklist') }}"
                   class="th-nav-link @if (Request::routeIs('admin.ticket.blacklist')) active @endif">
                    <i class="th-nav-icon fas fa-user-slash"></i>
                    <span class="th-nav-label">{{ __('Ticket Blacklist') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::ROLES_PERMISSIONS)
                <a href="{{ route('admin.roles.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.roles.*')) active @endif">
                    <i class="th-nav-icon fas fa-user-shield"></i>
                    <span class="th-nav-label">{{ __('Role Management') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::SETTINGS_PERMISSIONS)
                <a href="{{ route('admin.settings.index') . '#icons' }}"
                   class="th-nav-link @if (Request::routeIs('admin.settings.*')) active @endif">
                    <i class="th-nav-icon fas fa-sliders-h"></i>
                    <span class="th-nav-label">{{ __('Settings') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::API_PERMISSIONS)
                <a href="{{ route('admin.api.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.api.*')) active @endif">
                    <i class="th-nav-icon fas fa-code"></i>
                    <span class="th-nav-label">{{ __('Application API') }}</span>
                </a>
            @endcanany

            @canany(array_merge(
                PermissionGroups::USERS_PERMISSIONS,
                PermissionGroups::SERVERS_PERMISSIONS,
                PermissionGroups::PRODUCTS_PERMISSIONS,
                PermissionGroups::STORE_PERMISSIONS,
                PermissionGroups::VOUCHERS_PERMISSIONS,
                PermissionGroups::PARTNERS_PERMISSIONS,
                PermissionGroups::COUPONS_PERMISSIONS
            ))
                <div class="th-nav-group-title">{{ __('Management') }}</div>
            @endcanany

            @canany(PermissionGroups::USERS_PERMISSIONS)
                <a href="{{ route('admin.users.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.users.*')) active @endif">
                    <i class="th-nav-icon fas fa-users"></i>
                    <span class="th-nav-label">{{ __('Users') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::SERVERS_PERMISSIONS)
                <a href="{{ route('admin.servers.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.servers.*')) active @endif">
                    <i class="th-nav-icon fas fa-hdd"></i>
                    <span class="th-nav-label">{{ __('Servers') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::PRODUCTS_PERMISSIONS)
                <a href="{{ route('admin.products.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.products.*')) active @endif">
                    <i class="th-nav-icon fas fa-cubes"></i>
                    <span class="th-nav-label">{{ __('Products') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::PRODUCTS_PERMISSIONS)
                <a href="{{ route('admin.customplans.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.customplans.*')) active @endif">
                    <i class="th-nav-icon fas fa-sliders-h"></i>
                    <span class="th-nav-label">{{ __('Configurable plans') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::STORE_PERMISSIONS)
                <a href="{{ route('admin.store.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.store.*')) active @endif">
                    <i class="th-nav-icon fas fa-shopping-basket"></i>
                    <span class="th-nav-label">{{ __('Store') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::VOUCHERS_PERMISSIONS)
                <a href="{{ route('admin.vouchers.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.vouchers.*')) active @endif">
                    <i class="th-nav-icon fas fa-gift"></i>
                    <span class="th-nav-label">{{ __('Vouchers') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::PARTNERS_PERMISSIONS)
                <a href="{{ route('admin.partners.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.partners.*')) active @endif">
                    <i class="th-nav-icon fas fa-handshake"></i>
                    <span class="th-nav-label">{{ __('Partners') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::COUPONS_PERMISSIONS)
                <a href="{{ route('admin.coupons.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.coupons.*')) active @endif">
                    <i class="th-nav-icon fas fa-tags"></i>
                    <span class="th-nav-label">{{ __('Coupons') }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::USEFUL_LINKS_PERMISSIONS)
                <a href="{{ route('admin.usefullinks.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.usefullinks.*')) active @endif">
                    <i class="th-nav-icon fas fa-link"></i>
                    <span class="th-nav-label">{{ __('Useful Links') }}</span>
                </a>
            @endcanany

            @canany(array_merge(
                PermissionGroups::PAYMENTS_PERMISSIONS,
                PermissionGroups::LOGS_PERMISSIONS
            ))
                <div class="th-nav-group-title">{{ __('Logs') }}</div>
            @endcanany

            @canany(PermissionGroups::PAYMENTS_PERMISSIONS)
                <a href="{{ route('admin.payments.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.payments.*')) active @endif">
                    <i class="th-nav-icon fas fa-money-bill-wave"></i>
                    <span class="th-nav-label">{{ __('Payments') }}</span>
                    <span class="th-nav-badge th-badge-success">{{ $paymentCount }}</span>
                </a>
            @endcanany

            @canany(PermissionGroups::LOGS_PERMISSIONS)
                <a href="{{ route('admin.activitylogs.index') }}"
                   class="th-nav-link @if (Request::routeIs('admin.activitylogs.*')) active @endif">
                    <i class="th-nav-icon fas fa-clipboard-list"></i>
                    <span class="th-nav-label">{{ __('Activity Logs') }}</span>
                </a>
            @endcanany
            @endif
        </nav>

        @if ($canAdmin && $adminHome)
            <div class="th-area-switch-wrap">
                @if ($inAdmin)
                    <a href="{{ route('home') }}" class="th-area-switch">
                        <i class="fas fa-arrow-left"></i>
                        <span class="th-nav-label">{{ __('Back to client area') }}</span>
                    </a>
                @else
                    <a href="{{ $adminHome }}" class="th-area-switch is-admin">
                        <i class="fas fa-user-shield"></i>
                        <span class="th-nav-label">{{ __('Admin area') }}</span>
                    </a>
                @endif
            </div>
        @endif

        <div class="th-sidebar-foot">
            {{ config('app.name') }} &middot; v{{ config('app.version', '1.2.0') }}
        </div>
    </aside>

    {{-- Click-away layer for the mobile off-canvas sidebar --}}
    <div class="th-overlay" x-cloak x-show="mobile" @click="mobile = false"></div>

    {{-- ===================== MAIN ===================== --}}
    <div class="th-main">

        <header class="th-topbar">
            <button class="th-icon-btn" @click="toggle()" aria-label="{{ __('Toggle navigation') }}">
                <i class="fas fa-bars"></i>
            </button>

            <h1 class="th-topbar-title">@yield('page-title', config('app.name'))</h1>

            <div class="th-topbar-spacer"></div>

            {{-- Credits --}}
            <div class="dropdown d-none d-sm-block">
                <a class="th-credits" href="#" id="creditsDropdown" role="button" data-toggle="dropdown"
                   aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-coins"></i>
                    {{ Currency::formatForDisplay(Auth::user()->credits) }}
                </a>
                <div class="dropdown-menu dropdown-menu-right" aria-labelledby="creditsDropdown">
                    <div class="dropdown-header">{{ $general_settings->credits_display_name }}</div>
                    <a class="dropdown-item" href="{{ route('store.index') }}">
                        <i class="mr-2 fas fa-plus fa-sm fa-fw"></i>{{ __('Buy credits') }}
                    </a>
                    <a class="dropdown-item" data-toggle="modal" data-target="#redeemVoucherModal"
                       href="javascript:void(0)">
                        <i class="mr-2 fas fa-money-check-alt fa-sm fa-fw"></i>{{ __('Redeem code') }}
                    </a>
                </div>
            </div>

            {{-- Notifications --}}
            <a href="{{ route('notifications.index') }}" class="th-icon-btn position-relative"
               title="{{ __('Notifications') }}">
                <i class="fas fa-bell"></i>
                @if (Auth::user()->unreadNotifications->count() != 0)
                    <span class="th-dot"></span>
                @endif
            </a>

            {{-- User --}}
            <div class="dropdown">
                <a class="th-user-chip" href="#" id="userDropdown" role="button" data-toggle="dropdown"
                   aria-haspopup="true" aria-expanded="false">
                    <img class="th-user-avatar" src="{{ Auth::user()->getAvatar() }}" alt="">
                    <span class="d-none d-md-inline">{{ Auth::user()->name }}</span>
                    <i class="fas fa-chevron-down" style="font-size:.6rem;opacity:.6"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                    <div class="dropdown-header">{{ Auth::user()->email }}</div>
                    <a class="dropdown-item" href="{{ route('profile.index') }}">
                        <i class="mr-2 fas fa-user fa-sm fa-fw"></i>{{ __('Profile') }}
                    </a>
                    <a class="dropdown-item" href="{{ route('notifications.index') }}">
                        <i class="mr-2 fas fa-bell fa-sm fa-fw"></i>{{ __('Notifications') }}
                        @if (Auth::user()->unreadNotifications->count() != 0)
                            <span class="badge badge-warning ml-auto">
                                {{ Auth::user()->unreadNotifications->count() }}
                            </span>
                        @endif
                    </a>
                    <a class="dropdown-item" href="{{ route('preferences.index') }}">
                        <i class="mr-2 fas fa-cog fa-sm fa-fw"></i>{{ __('Preferences') }}
                    </a>
                    @if (session()->get('previousUser'))
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="{{ route('users.logbackin') }}">
                            <i class="mr-2 fas fa-sign-in-alt fa-sm fa-fw"></i>{{ __('Log back in') }}
                        </a>
                    @endif
                    <div class="dropdown-divider"></div>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="dropdown-item" type="submit">
                            <i class="mr-2 fas fa-sign-out-alt fa-sm fa-fw"></i>{{ __('Logout') }}
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="th-content">
            @if ($general_settings->alert_enabled && !empty($general_settings->alert_message))
                <div class="alert alert-{{ $general_settings->alert_type }}" role="alert">
                    {!! $general_settings->alert_message !!}
                </div>
            @endif

            @yield('content')

            @include('modals.redeem_voucher_modal')
        </main>

        <footer class="th-footer d-flex flex-wrap justify-content-between">
            <div>
                &copy; {{ date('Y') }} <a href="{{ url('/') }}"><b>{{ config('app.name', 'TrueHosting') }}</b></a>.
                {{ __('All rights reserved.') }}
                {{ __('Powered by') }} <a href="https://CtrlPanel.gg" target="_blank">CtrlPanel</a>.
            </div>

            @if ($website_settings->show_imprint || $website_settings->show_privacy || $website_settings->show_tos)
                <div>
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
        </footer>
    </div>
</div>

{{-- ===================== SCRIPTS ===================== --}}
<script src="{{ asset('plugins/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('plugins/datatables/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('plugins/summernote/summernote-bs4.min.js') }}"></script>
<script src="{{ asset('plugins/select2/js/select2.min.js') }}"></script>
<script src="{{ asset('plugins/moment/moment.min.js') }}"></script>
<script src="{{ asset('plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js') }}"></script>

<script>
    // Dim the current page the moment a same-site navigation starts, so a slow
    // response does not look like a frozen click.
    (function () {
        const leaving = () => document.body.classList.add('th-leaving');

        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            const link = e.target.closest('a[href]');
            if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
            if (link.dataset.toggle || link.getAttribute('role') === 'button') return;

            const href = link.getAttribute('href') || '';
            if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return;
            if (link.origin !== window.location.origin) return;

            leaving();
        }, true);

        // Bubble phase, and only when nothing cancelled the submit: a form
        // that blocks itself to point out a missing field is not navigating,
        // so the page must not dim as though it were.
        document.addEventListener('submit', function (e) {
            if (!e.defaultPrevented) leaving();
        });

        // Returning through the back/forward cache must not leave it dimmed.
        window.addEventListener('pageshow', function () {
            document.body.classList.remove('th-leaving');
        });
    })();

    // Modals are written inline, deep inside the page. Any ancestor that forms a
    // stacking context would leave them painted under the backdrop and dead to
    // clicks, so each one is hoisted to the body the first time it opens.
    $(document).on('show.bs.modal', '.modal', function () {
        if (this.parentElement !== document.body) {
            document.body.appendChild(this);
        }
    });

    $(document).ready(function () {
        $('[data-toggle="popover"]').popover();

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
    });

    $(document).on('alpine:init', () => {
        Alpine.magic('currency', () => {
            return {
                format: (amount) => {
                    return (amount / 1000);
                },
            }
        });
    })
</script>

<script>
    const thToast = (icon, title) => Swal.fire({
        icon: icon,
        title: title,
        position: 'top-end',
        showConfirmButton: false,
        toast: true,
        timer: 3200,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
            toast.addEventListener('click', () => Swal.close())
        }
    });

    @if (Session::has('error'))
        Swal.fire({
            icon: 'error',
            title: 'Oops...',
            html: '{{ Session::get('error') }}',
        })
    @endif
    @if (Session::has('success'))
        thToast('success', '{{ Session::get('success') }}');
    @endif
    @if (Session::has('info'))
        thToast('info', '{{ Session::get('info') }}');
    @endif
    @if (Session::has('warning'))
        thToast('warning', '{{ Session::get('warning') }}');
    @endif
</script>
</body>

</html>
