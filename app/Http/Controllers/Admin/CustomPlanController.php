<?php

namespace App\Http\Controllers\Admin;

use App\Facades\Currency;
use App\Http\Controllers\Controller;
use App\Models\CustomPlan;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Node;
use App\Settings\GeneralSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomPlanController extends Controller
{
    public const READ_PERMISSION = 'admin.products.read';

    public const WRITE_PERMISSION = 'admin.products.write';

    public function index()
    {
        $this->checkPermission(self::READ_PERMISSION);

        return view('admin.customplans.index', [
            'plans' => CustomPlan::withCount('nodes')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->checkPermission(self::WRITE_PERMISSION);

        return view('admin.customplans.form', [
            'credits_display_name' => app(GeneralSettings::class)->credits_display_name,
            'plan' => new CustomPlan(),
            'nodes' => Node::orderBy('name')->get(),
            'eggs' => Egg::with('nest')->orderBy('name')->get(),
            'selectedNodes' => [],
            'selectedEggs' => [],
        ]);
    }

    public function edit(CustomPlan $customplan)
    {
        $this->checkPermission(self::WRITE_PERMISSION);

        return view('admin.customplans.form', [
            'credits_display_name' => app(GeneralSettings::class)->credits_display_name,
            'plan' => $customplan,
            'nodes' => Node::orderBy('name')->get(),
            'eggs' => Egg::with('nest')->orderBy('name')->get(),
            'selectedNodes' => $customplan->nodes->keyBy('id'),
            'selectedEggs' => $customplan->eggs->pluck('id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->checkPermission(self::WRITE_PERMISSION);

        $plan = CustomPlan::create($this->validated($request));
        $this->syncRelations($plan, $request);

        return redirect()->route('admin.customplans.index')
            ->with('success', __('Plan saved'));
    }

    public function update(Request $request, CustomPlan $customplan): RedirectResponse
    {
        $this->checkPermission(self::WRITE_PERMISSION);

        $customplan->update($this->validated($request));
        $this->syncRelations($customplan, $request);

        return redirect()->route('admin.customplans.index')
            ->with('success', __('Plan saved'));
    }

    public function destroy(CustomPlan $customplan): RedirectResponse
    {
        $this->checkPermission(self::WRITE_PERMISSION);

        $customplan->delete();

        return redirect()->route('admin.customplans.index')
            ->with('success', __('Plan deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:191',
            'disabled' => 'nullable',
            'billing_period' => 'required|in:hourly,daily,weekly,monthly,quarterly,half-annually,annually',

            'price_cpu' => 'required|numeric|min:0',
            'price_memory' => 'required|numeric|min:0',
            'price_disk' => 'required|numeric|min:0',
            'price_backup' => 'required|numeric|min:0',
            'price_database' => 'required|numeric|min:0',
            'price_allocation' => 'required|numeric|min:0',

            'money_price_cpu' => 'required|numeric|min:0',
            'money_price_memory' => 'required|numeric|min:0',
            'money_price_disk' => 'required|numeric|min:0',
            'money_price_backup' => 'required|numeric|min:0',
            'money_price_database' => 'required|numeric|min:0',
            'money_price_allocation' => 'required|numeric|min:0',

            'cpu_min' => 'required|integer|min:1',
            'cpu_max' => 'required|integer|gte:cpu_min',
            'cpu_step' => 'required|integer|min:1',
            'memory_min' => 'required|integer|min:1',
            'memory_max' => 'required|integer|gte:memory_min',
            'memory_step' => 'required|integer|min:1',
            'disk_min' => 'required|integer|min:1',
            'disk_max' => 'required|integer|gte:disk_min',
            'disk_step' => 'required|integer|min:1',
            'backups_min' => 'required|integer|min:0',
            'backups_max' => 'required|integer|gte:backups_min',
            'databases_min' => 'required|integer|min:0',
            'databases_max' => 'required|integer|gte:databases_min',
            'allocations_min' => 'required|integer|min:1',
            'allocations_max' => 'required|integer|gte:allocations_min',

            'discount_per_unit' => 'required|numeric|min:0|max:100',
            'discount_max' => 'required|numeric|min:0|max:100',
            'large_order_memory' => 'nullable|integer|min:1',

            'payment_modes' => 'required|in:credits,direct,both',
            'credit_rate' => 'nullable|numeric|min:0',
            'currency_code' => 'required|string|size:3',
            'grace_days' => 'required|integer|min:0|max:60',

            'swap' => 'required|integer|min:-1',
            'io' => 'required|integer|min:10|max:1000',
            'oom_killer' => 'nullable',
            'minimum_credits' => 'required|numeric|min:-1',

            'nodes' => 'nullable|array',
            'nodes.*' => 'integer',
            'eggs' => 'nullable|array',
            'eggs.*' => 'integer',
        ]);

        // Both price lists are entered per unit and stored in the 1000x
        // integer scale: one in credits, one in the plan's currency.
        foreach (array_keys(CustomPlan::RESOURCES) as $resource) {
            foreach ([CustomPlan::RESOURCES[$resource]['price'], CustomPlan::RESOURCES[$resource]['money']] as $field) {
                $data[$field] = Currency::prepareForDatabase((float) $data[$field]);
            }
        }

        $data['minimum_credits'] = $data['minimum_credits'] == -1
            ? -1
            : Currency::prepareForDatabase((float) $data['minimum_credits']);

        $data['credit_rate'] = (float) ($data['credit_rate'] ?? 0);
        $data['currency_code'] = strtoupper($data['currency_code']);
        $data['disabled'] = $request->boolean('disabled');
        $data['oom_killer'] = $request->boolean('oom_killer');

        unset($data['nodes'], $data['eggs']);

        return $data;
    }

    private function syncRelations(CustomPlan $plan, Request $request): void
    {
        $nodeIds = array_map('intval', (array) $request->input('nodes', []));

        $pivot = [];
        foreach ($nodeIds as $nodeId) {
            $pivot[$nodeId] = [
                'priority' => (int) $request->input('node_priority.' . $nodeId, 0),
                'allow_large' => (bool) $request->input('node_allow_large.' . $nodeId, false),
            ];
        }

        $plan->nodes()->sync($pivot);
        $plan->eggs()->sync(array_map('intval', (array) $request->input('eggs', [])));
    }
}
