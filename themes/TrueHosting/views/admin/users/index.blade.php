@extends('layouts.main')

@section('content')
    <!-- CONTENT HEADER -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="mb-2 row">
                <div class="col-sm-6">
                    <h1>{{ __('Users') }}</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}">{{ __('Dashboard') }}</a></li>
                        <li class="breadcrumb-item"><a class="text-muted"
                                href="{{ route('admin.users.index') }}">{{ __('Users') }}</a></li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <!-- END CONTENT HEADER -->

    <!-- MAIN CONTENT -->
    <section class="content">
        <div class="container-fluid">

            <div class="card">

                <div class="card-header">
                    <div class="d-flex justify-content-between">
                        <h5 class="card-title"><i class="mr-2 fas fa-users"></i>{{ __('Users') }}</h5>
                        <div class="d-flex align-items-center" style="gap:.5rem">
                            @can('admin.users.delete')
                                <form id="bulkDeleteForm" method="POST"
                                      action="{{ route('admin.users.bulkDestroy') }}" class="d-none">
                                    @csrf
                                    @method('DELETE')
                                    <div id="bulkIds"></div>
                                    <button type="submit" class="th-btn th-btn-ghost th-btn-sm" style="color:#f87171">
                                        <i class="fas fa-trash"></i>
                                        {{ __('Delete selected') }} (<span id="bulkCount">0</span>)
                                    </button>
                                </form>
                            @endcan
                            <a href="{{ route('admin.users.notifications.index') }}" class="th-btn th-btn-ghost th-btn-sm"><i
                                    class="mr-1 fas fa-paper-plane"></i>{{ __('Notify') }}</a>
                        </div>
                    </div>
                </div>

                <div class="card-body table-responsive">

                    <table id="datatable" class="table table-striped">
                        <thead>
                        <tr>
                            <th style="width:34px">
                                <input type="checkbox" id="bulkSelectAll" aria-label="select all">
                            </th>
                            <th>discordId</th>
                            <th>ip</th>
                            <th>pterodactyl_id</th>
                            <th>{{__('Avatar')}}</th>
                            <th>{{__('Name')}}</th>
                            <th>{{__('Role')}}</th>
                            <th>{{__('Email')}}</th>
                            <th>{{ $credits_display_name }}</th>
                            <th>{{__('Servers')}}</th>
                            <th>{{__('Referrals')}}</th>
                            <th>{{__('Verified')}}</th>
                            <th>{{__('Last seen')}}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>

                </div>
            </div>


        </div>
        <!-- END CUSTOM CONTENT -->
    </section>
    <!-- END CONTENT -->

    <script>
        function submitResult() {
            return confirm("{{ __('Are you sure you wish to delete?') }}") !== false;
        }

        document.addEventListener("DOMContentLoaded", function() {
            $('#datatable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.3/i18n/{{ $locale_datatables }}.json'
                },
                processing: true,
                serverSide: true, //why was this set to false before? increased loadingtimes by 10 seconds
                stateSave: true,
                ajax: "{{ route('admin.users.datatable') }}{{ $filter ?? '' }}",
                order: [
                    [12, "desc"]
                ],
                columns: [
                    {
                        data: 'select',
                        name: 'select',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'discordId',
                        visible: false,
                        name: 'discordUser.id'
                    },
                    {
                        data: 'pterodactyl_id',
                        visible: false
                    },
                    {
                        data: 'ip',
                        visible: false
                    },
                    {
                        data: 'avatar',
                        sortable: false
                    },
                    {
                        data: 'name'
                    },
                    {
                        data: 'role',
                        name: 'roles.name'
                    },
                    {
                        data: 'email',
                        name: 'users.email'
                    },
                    {
                        data: 'credits',
                        name: 'users.credits'
                    },
                    {
                        data: 'servers_count',
                        searchable: false
                    },
                    {
                        data: 'referrals_count',
                        searchable: false
                    },
                    {
                        data: 'verified',
                        sortable: false
                    },
                    {
                        data: 'last_seen',
                    },
                    {
                        data: 'actions',
                        sortable: false
                    },
                ],
                fnDrawCallback: function(oSettings) {
                    $('[data-toggle="popover"]').popover();
                }
            });
        });
    </script>

    <script>
        // Selection lives in JS because the table is paginated server-side:
        // ids are collected as they are ticked, not read from the DOM at submit.
        (function () {
            const selected = new Set();
            const form = document.getElementById('bulkDeleteForm');
            const holder = document.getElementById('bulkIds');
            const counter = document.getElementById('bulkCount');
            if (!form) return;

            function render() {
                counter.textContent = selected.size;
                form.classList.toggle('d-none', selected.size === 0);

                holder.innerHTML = '';
                selected.forEach(function (id) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = id;
                    holder.appendChild(input);
                });
            }

            document.addEventListener('change', function (e) {
                if (e.target.classList.contains('th-bulk-item')) {
                    e.target.checked ? selected.add(e.target.value) : selected.delete(e.target.value);
                    render();
                }

                if (e.target.id === 'bulkSelectAll') {
                    document.querySelectorAll('.th-bulk-item').forEach(function (box) {
                        box.checked = e.target.checked;
                        e.target.checked ? selected.add(box.value) : selected.delete(box.value);
                    });
                    render();
                }
            });

            // Re-tick rows that are still selected after paging or searching.
            $('#datatable').on('draw.dt', function () {
                document.querySelectorAll('.th-bulk-item').forEach(function (box) {
                    box.checked = selected.has(box.value);
                });
                const all = document.getElementById('bulkSelectAll');
                if (all) all.checked = false;
            });

            form.addEventListener('submit', function (e) {
                const question = '{{ __('Delete the selected users? This cannot be undone.') }}';

                if (selected.size === 0 || !confirm(question + ' (' + selected.size + ')')) {
                    e.preventDefault();
                }
            });
        })();
    </script>
@endsection
