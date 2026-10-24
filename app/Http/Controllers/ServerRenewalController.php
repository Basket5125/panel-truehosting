<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ServerRenewalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ServerRenewalController extends Controller
{
    public function __construct(private ServerRenewalService $renewals)
    {
        $this->middleware('auth');
    }

    public function renew(Request $request, Server $server): RedirectResponse
    {
        // A customer may only renew their own server.
        abort_unless($server->user_id === $request->user()->id, 403);

        $request->validate([
            'mode' => 'required|in:credits,direct',
        ]);

        if (!$server->isDirectBilled()) {
            return back()->with('error', __('This server is billed from credits automatically.'));
        }

        $lockKey = 'server_renew_' . $server->id;
        if (Cache::has($lockKey)) {
            return back()->with('error', __('Please wait a moment before trying again.'));
        }
        Cache::put($lockKey, true, 5);

        try {
            if ($request->input('mode') === 'credits') {
                $until = $this->renewals->renewWithCredits($server, $request->user());
                Cache::forget($lockKey);

                return back()->with('success', __('Renewed until :date', [
                    'date' => $until->toDayDateTimeString(),
                ]));
            }

            $order = $this->renewals->prepareDirectPayment($server, $request->user());
            Cache::forget($lockKey);

            return redirect()->route('checkout', $order->shop_product_id);
        } catch (Throwable $e) {
            Cache::forget($lockKey);

            return back()->with('error', $e->getMessage());
        }
    }
}
