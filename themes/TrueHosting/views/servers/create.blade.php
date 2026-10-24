@extends('layouts.main')

@section('page-title', __('Create Server'))

@section('content')
    <section x-data="serverApp()" x-init="init()">
        <div class="th-page-head d-flex flex-wrap align-items-end justify-content-between" style="gap:1rem">
            <div>
                <h2 class="th-page-title">{{ __('Create Server') }}</h2>
                <p class="th-page-sub">
                    {{ __('Choose the software, a location and the package. Setup starts right after that.') }}
                </p>
            </div>

            <a href="{{ route('servers.index') }}" class="th-btn th-btn-ghost">
                <i class="fas fa-arrow-left"></i>{{ __('Servers') }}
            </a>
        </div>

        @if (!$server_creation_enabled)
            <div class="mb-3 alert alert-warning">
                {{ __('The creation of new servers has been disabled for regular users, enable it again') }}
                <a href="{{ route('admin.settings.index', '#Server') }}">{{ __('here') }}</a>.
            </div>
        @endif

        @if ($productCount === 0 || $nodeCount === 0 || count($nests) === 0 || count($eggs) === 0)
            <div class="mb-3 alert alert-danger">
                <h5 class="mb-2"><i class="mr-2 icon fas fa-exclamation-circle"></i>{{ __('Error!') }}</h5>
                @if (Auth::user()->hasRole('Admin'))
                    <p class="mb-2">
                        {{ __('Make sure to link your products to nodes and eggs.') }}<br>
                        {{ __('There has to be at least 1 valid product for server creation') }}
                        <a href="{{ route('admin.overview.sync') }}">{{ __('Sync now') }}</a>
                    </p>
                @endif
                <ul class="mb-0 pl-3">
                    @if ($productCount === 0)
                        <li>{{ __('No products available!') }}</li>
                    @endif
                    @if ($nodeCount === 0)
                        <li>{{ __('No nodes have been linked!') }}</li>
                    @endif
                    @if (count($nests) === 0)
                        <li>{{ __('No nests available!') }}</li>
                    @endif
                    @if (count($eggs) === 0)
                        <li>{{ __('No eggs have been linked!') }}</li>
                    @endif
                </ul>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-3 alert alert-danger">
                <ul class="mb-0 pl-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('servers.store') }}" x-on:submit="submitClicked = true" method="post" id="serverForm"
            class="th-create">
            @csrf

            <div class="th-create-config">
                <div class="th-card">
                    <div class="th-card-head">
                        <i class="fas fa-cogs"></i>
                        <h3 class="th-card-title">{{ __('Server configuration') }}</h3>
                    </div>

                    <div class="th-card-body">
                        <div class="form-group">
                            <label for="name">{{ __('Name') }}</label>
                            <input x-model="name" id="name" name="name" type="text" required="required"
                                placeholder="{{ __('Name') }}"
                                class="form-control @error('name') is-invalid @enderror">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label for="nest">{{ __('Software / Games') }}</label>
                            <select class="custom-select" required name="nest" id="nest" x-model="selectedNest"
                                @change="setEggs();">
                                <option selected disabled hidden value="null">
                                    {{ count($nests) > 0 ? __('Please select software ...') : __('---') }}
                                </option>
                                @foreach ($nests as $nest)
                                    <option value="{{ $nest->id }}">{{ $nest->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="egg">{{ __('Specification ') }}</label>
                            <select id="egg" required name="egg" :disabled="eggs.length == 0" x-model="selectedEgg"
                                @change="fetchLocations();" required="required" class="custom-select">
                                <option x-text="getEggInputText()" selected disabled hidden value="null"></option>
                                <template x-for="egg in eggs" :key="egg.id">
                                    <option x-text="egg.name" :value="egg.id"></option>
                                </template>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="location">
                                {{ __('Location') }}
                                @if ($location_description_enabled)
                                    <i x-show="locationDescription != null" data-toggle="popover" data-trigger="click"
                                        x-bind:data-content="locationDescription" class="fas fa-info-circle"></i>
                                @endif
                            </label>
                            <select name="location" required id="location" x-model="selectedLocation"
                                :disabled="!fetchedLocations" @change="fetchProducts();" class="custom-select">
                                <option x-text="getLocationInputText()" disabled selected hidden value="null"></option>
                                <template x-for="location in locations" :key="location.id">
                                    <option x-text="location.name" :value="location.id"></option>
                                </template>
                            </select>
                        </div>

                        <div class="form-group mb-0">
                            <label for="billing_priority">
                                {{ __('Billing Priority') }}
                                <i data-toggle="popover" data-trigger="hover"
                                    data-content="{{ __('Defines the priority for server billing. If not provided, the value of selected product will be used.') }}"
                                    class="fas fa-info-circle"></i>
                            </label>
                            <select id="billing_priority" class="custom-select" name="billing_priority" required
                                autocomplete="off">
                                <option value="" selected>{{ __('Select') }}</option>
                                @foreach (App\Enums\BillingPriority::cases() as $priority)
                                    <option value="{{ $priority->value }}">
                                        {{ $priority->label() }} - {{ $priority->description() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="loading" x-cloak class="th-create-loading">
                            <i class="fas fa-sync-alt fa-spin"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="th-create-products" x-data="{
                billingPeriodTranslations: {
                    'monthly': '{{ __('per Month') }}',
                    'half-annually': '{{ __('per 6 Months') }}',
                    'quarterly': '{{ __('per 3 Months') }}',
                    'annually': '{{ __('per Year') }}',
                    'weekly': '{{ __('per Week') }}',
                    'daily': '{{ __('per Day') }}',
                    'hourly': '{{ __('per Hour') }}'
                }
            }">
                <div x-show="!fetchedProducts && !loading" class="th-empty th-create-hint">
                    <i class="fas fa-server"></i>
                    <h3 class="th-empty-title">{{ __('Packages show up here') }}</h3>
                    <p class="th-empty-text mb-0">
                        {{ __('Fill in the configuration on the left — the packages available in that location will be listed here.') }}
                    </p>
                </div>

                <div x-show="loading" x-cloak class="th-empty th-create-hint">
                    <i class="fas fa-sync-alt fa-spin"></i>
                    <p class="th-empty-text mb-0">{{ __('Loading') }}</p>
                </div>

                <div x-show="fetchedProducts && products.length === 0 && !loading" x-cloak
                    class="th-empty th-create-hint">
                    <i class="fas fa-ban"></i>
                    <h3 class="th-empty-title">{{ __('No resources found matching current configuration') }}</h3>
                    <p class="th-empty-text mb-0">
                        {{ __('There seem to be no nodes available for this specification. Admins have been notified. Please try again later of contact us.') }}
                    </p>
                </div>

                <div x-show="fetchedProducts && products.length > 0" x-cloak class="th-grid">
                    <template x-for="product in products" :key="product.id">
                        <div class="th-plan">
                            <div class="d-flex justify-content-between align-items-start" style="gap:.75rem">
                                <h3 class="th-plan-name" x-text="product.name"></h3>
                                <span class="th-plan-slots"
                                    x-text="product.serverlimit > 0
                                        ? product.servers_count + ' / ' + product.serverlimit
                                        : '{{ __('No limit') }}'"></span>
                            </div>

                            <div class="th-plan-price">
                                <span class="th-plan-amount" x-text="product.display_price"></span>
                                <span class="th-plan-unit"
                                    x-text="'{{ $credits_display_name }} ' + billingPeriodTranslations[product.billing_period]"></span>
                            </div>
                            <p class="th-plan-note" x-show="product.description" x-text="product.description"
                                style="white-space:pre-wrap"></p>

                            <ul class="th-plan-specs">
                                <li>
                                    <span><i class="fas fa-microchip"></i>{{ __('CPU') }}</span>
                                    <span x-text="product.cpu === 0 ? '{{ __('Unlimited') }}' : product.cpu + ' {{ __('vCores') }}'"></span>
                                </li>
                                <li>
                                    <span><i class="fas fa-memory"></i>{{ __('Memory') }}</span>
                                    <span x-text="product.memory === 0 ? '{{ __('Unlimited') }}' : product.memory + ' {{ __('MB') }}'"></span>
                                </li>
                                <li>
                                    <span><i class="fas fa-hdd"></i>{{ __('Disk') }}</span>
                                    <span x-text="product.disk === 0 ? '{{ __('Unlimited') }}' : product.disk + ' {{ __('MB') }}'"></span>
                                </li>
                                <li>
                                    <span><i class="fas fa-save"></i>{{ __('Backups') }}</span>
                                    <span x-text="product.backups"></span>
                                </li>
                                <li>
                                    <span><i class="fas fa-database"></i>{{ __('MySQL Databases') }}</span>
                                    <span x-text="product.databases"></span>
                                </li>
                                <li>
                                    <span><i class="fas fa-network-wired"></i>{{ __('Additional allocations (ports)') }}</span>
                                    <span x-text="product.allocations"></span>
                                </li>
                                <li>
                                    <span><i class="fas fa-flag"></i>{{ __('Billing Priority') }}</span>
                                    <span x-text="product.default_billing_priority_label"></span>
                                </li>
                                <li>
                                    <span><i class="fa fa-coins"></i>{{ __('Minimum') }} {{ $credits_display_name }}</span>
                                    <span x-text="product.display_minimum_credits"></span>
                                </li>
                            </ul>

                            <div class="th-card-foot">
                                <button type="button"
                                    :disabled="(product.effective_minimum > user.credits) ||
                                        product.doesNotFit == true ||
                                        product.servers_count >= product.serverlimit && product.serverlimit != 0 ||
                                        submitClicked"
                                    class="th-btn th-btn-primary th-btn-block" @click="setProduct(product.id);"
                                    x-text="product.doesNotFit == true
                                        ? '{{ __('Server cant fit on this Location') }}'
                                        : (product.servers_count >= product.serverlimit && product.serverlimit != 0
                                            ? '{{ __('Max. Servers with configuration reached') }}'
                                            : (product.effective_minimum > user.credits
                                                ? '{{ __('Not enough') }} {{ $credits_display_name }}!'
                                                : '{{ __('Create server') }}'))">
                                </button>

                                @if (env('APP_ENV') == 'local' || $store_enabled)
                                    <template x-if="product.effective_minimum > user.credits">
                                        <a href="{{ route('store.index') }}" class="th-btn th-btn-ghost th-btn-block">
                                            {{ __('Buy more') }} {{ $credits_display_name }}
                                        </a>
                                    </template>
                                @endif
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="product" id="product" x-model="selectedProduct">
            <input type="hidden" name="egg_variables" id="egg_variables">
        </form>
    </section>

    <script>
        function serverApp() {
            return {
                //loading
                loading: false,
                fetchedLocations: false,
                fetchedProducts: false,

                //input fields
                name: null,
                selectedNest: null,
                selectedEgg: null,
                selectedLocation: null,
                selectedProduct: null,
                locationDescription: null,

                //selected objects based on input
                selectedNestObject: {},
                selectedEggObject: {},
                selectedLocationObject: {},
                selectedProductObject: {},

                //values
                user: {!! $user !!},
                nests: {!! $nests !!},
                eggsSave: {!! $eggs !!}, //store back-end eggs
                eggs: [],
                locations: [],
                products: [],

                submitClicked: false,


                /**
                 * @description set available eggs based on the selected nest
                 * @note called whenever a nest is selected
                 * @see selectedNest
                 */
                /**
                 * @description preselect anything there is only one of, so
                 * the customer is not asked to "choose" from a list of one
                 */
                async init() {
                    if (this.nests.length === 1) {
                        this.selectedNest = this.nests[0].id;
                        await this.setEggs();
                    }
                },

                async setEggs() {
                    this.fetchedLocations = false;
                    this.fetchedProducts = false;
                    this.locations = [];
                    this.products = [];
                    this.selectedEgg = 'null';
                    this.selectedLocation = 'null';
                    this.selectedProduct = null;
                    this.locationDescription = 'null';

                    this.eggs = this.eggsSave.filter(egg => egg.nest_id == this.selectedNest)

                    //automatically select the first entry if there is only 1
                    if (this.eggs.length === 1) {
                        this.selectedEgg = this.eggs[0].id;
                        await this.fetchLocations();
                        return;
                    }

                    this.updateSelectedObjects()
                },

                setProduct(productId) {
                    if (!productId) return

                    this.selectedProduct = productId;
                    this.updateSelectedObjects();

                    let hasEmptyRequiredVariables = this.hasEmptyRequiredVariables(this.selectedEggObject.environment);

                    if (hasEmptyRequiredVariables.length > 0) {
                        this.dispatchModal(hasEmptyRequiredVariables);
                    } else {
                        document.getElementById('product').value = productId;
                        document.getElementById('serverForm').submit();
                    }
                },

                /**
                 * @description fetch all available locations based on the selected egg
                 * @note called whenever a server configuration is selected
                 * @see selectedEg
                 */
                async fetchLocations() {
                    this.loading = true;
                    this.fetchedLocations = false;
                    this.fetchedProducts = false;
                    this.locations = [];
                    this.products = [];
                    this.selectedLocation = 'null';
                    this.selectedProduct = 'null';
                    this.locationDescription = null;

                    let response = await axios.get(`{{ route('products.locations.egg') }}/${this.selectedEgg}`)
                        .catch(console.error)

                    this.fetchedLocations = true;
                    this.locations = response.data

                    //automatically select the first entry if there is only 1
                    if (this.locations.length === 1) {
                        this.selectedLocation = this.locations[0]?.id;

                        await this.fetchProducts();
                        return;
                    }

                    this.loading = false;
                    this.updateSelectedObjects()
                },

                /**
                 * @description fetch all available products based on the selected node
                 * @note called whenever a node is selected
                 * @see selectedLocation
                 */
                async fetchProducts() {
                    this.loading = true;
                    this.fetchedProducts = false;
                    this.products = [];
                    this.selectedProduct = null;

                    let response = await axios.get(
                            `{{ route('products.products.location') }}/${this.selectedEgg}/${this.selectedLocation}`)
                        .catch(console.error)

                    this.fetchedProducts = true;

                    // TODO: Sortable by user chosen property (cpu, ram, disk...)
                    this.products = response.data.sort((p1, p2) => parseInt(p1.price, 10) > parseInt(p2.price, 10) &&
                        1 || -1)

                    //divide cpu by 100 for each product
                    this.products.forEach(product => {
                        product.cpu = product.cpu / 100;
                        // Determine effective minimum credits: fallback to price when stored value
                        // is missing or less than price. legacy -1 rows are covered by the comparison.
                        let minVal = parseFloat(product.minimum_credits);
                        product.effective_minimum = (product.minimum_credits === null || minVal < parseFloat(
                                product.price)) ?
                            parseFloat(product.price) :
                            minVal;
                    })

                    this.locationDescription = this.locations.find(location => location.id == this.selectedLocation)
                        .description ?? null;
                    this.loading = false;
                    this.updateSelectedObjects()
                },


                /**
                 * @description map selected id's to selected objects
                 * @note being used in the server info box
                 */
                updateSelectedObjects() {
                    this.selectedNestObject = this.nests.find(nest => nest.id == this.selectedNest) ?? {}
                    this.selectedEggObject = this.eggs.find(egg => egg.id == this.selectedEgg) ?? {}

                    this.selectedLocationObject = {};
                    this.locations.forEach(location => {
                        if (!this.selectedLocationObject?.id) {
                            this.selectedLocationObject = location.nodes.find(node => node.id == this
                                .selectedLocation) ?? {};
                        }
                    })

                    this.selectedProductObject = this.products.find(product => product.id == this.selectedProduct) ?? {}
                },

                /**
                 * @description check if all options are selected
                 * @return {boolean}
                 */
                isFormValid() {
                    if (Object.keys(this.selectedNestObject).length === 0) return false;
                    if (Object.keys(this.selectedEggObject).length === 0) return false;
                    if (Object.keys(this.selectedLocationObject).length === 0) return false;
                    if (Object.keys(this.selectedProductObject).length === 0) return false;
                    return !!this.name;
                },

                hasEmptyRequiredVariables(environment) {
                    if (!environment) return [];

                    return environment.filter((variable) => {
                        const hasRequiredRule = variable.rules?.includes("required");
                        const isDefaultNull = !variable.default_value;

                        return hasRequiredRule && isDefaultNull;
                    });
                },

                getLocationInputText() {
                    if (this.fetchedLocations) {
                        if (this.locations.length > 0) {
                            return '{{ __('Please select a location ...') }}';
                        }
                        return '{{ __('No location found matching current configuration') }}'
                    }
                    return '{{ __('---') }}';
                },

                getProductInputText() {
                    if (this.fetchedProducts) {
                        if (this.products.length > 0) {
                            return '{{ __('Please select a resource ...') }}';
                        }
                        return '{{ __('No resources found matching current configuration') }}'
                    }
                    return '{{ __('---') }}';
                },

                getEggInputText() {
                    if (this.selectedNest) {
                        return '{{ __('Please select a configuration ...') }}';
                    }
                    return '{{ __('---') }}';
                },

                getProductOptionText(product) {
                    let text = product.name + ' (' + product.description + ')';

                    // Use effective_minimum for credit checks (minimum_credits is per-product; null uses price)
                    if (product.effective_minimum > this.user.credits) {
                        return '{{ __('Not enough credits!') }} | ' + text;
                    }

                    return text;
                },

                dispatchModal(variables) {
                    const html = variables.map(variable => {
                        const control = variable.rules.includes('in:') ?
                            (() => {
                                const inValues = variable.rules.match(/in:([^|]+)/)[1].split(',');
                                return `
                                    <select name="${variable.env_variable}" id="${variable.env_variable}" required="required" class="custom-select">
                                        ${inValues.map(value => `<option value="${value}">${value}</option>`).join('')}
                                    </select>
                                `;
                            })() :
                            `<input id="${variable.env_variable}" name="${variable.env_variable}" type="text" required="required" class="form-control">`;

                        return `
                            <div class="text-left form-group">
                                <div class="d-flex justify-content-between">
                                    <label for="${variable.env_variable}">${variable.name}</label>
                                    ${variable.description ? `
                                            <span>
                                                <i data-toggle="tooltip" data-placement="top" title="${variable.description}" class="fas fa-info-circle"></i>
                                            </span>
                                        ` : ''}
                                </div>
                                ${control}
                                <div id="${variable.env_variable}-error" class="mt-1"></div>
                            </div>
                        `;
                    }).join('');

                    Swal.fire({
                        title: '{{ __('Required Variables') }}',
                        html: html,
                        confirmButtonText: '{{ __('Submit') }}',
                        showCancelButton: true,
                        cancelButtonText: '{{ __('Cancel') }}',
                        showLoaderOnConfirm: true,
                        preConfirm: async () => {
                            const filledVariables = variables.map(variable => {
                                const value = document.getElementById(variable.env_variable).value;
                                return {
                                    ...variable,
                                    filled_value: value
                                };
                            });

                            const response = await fetch(
                            '{{ route('servers.validateDeploymentVariables') }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                },
                                body: JSON.stringify({
                                    variables: filledVariables
                                })
                            })

                            if (!response.ok) {
                                const errorData = await response.json();

                                variables.forEach(variable => {
                                    const errorContainer = document.getElementById(
                                        `${variable.env_variable}-error`);
                                    if (errorContainer) {
                                        errorContainer.innerHTML = '';
                                    }
                                });

                                if (errorData.errors) {
                                    Object.entries(errorData.errors).forEach(([key, messages]) => {
                                        const errorContainer = document.getElementById(`${key}-error`);
                                        if (errorContainer) {
                                            errorContainer.innerHTML = messages.map(message => `
                                        <small class="text-danger">${message}</small>
                                    `).join('');
                                        }
                                    });
                                }

                                return false;
                            }

                            return response.json();
                        },
                        didOpen: () => {
                            $('[data-toggle="tooltip"]').tooltip();
                        },
                    }).then((result) => {
                        if (result.isConfirmed && result.value.success) {
                            let variables = result.value.variables.reduce((acc, variable) => {
                                acc[variable.env_variable] = variable.filled_value;
                                return acc;
                            }, {});

                            document.getElementById('egg_variables').value = JSON.stringify(variables);
                            document.getElementById('serverForm').submit();
                        }
                    });
                }
            }
        }
    </script>
@endsection
