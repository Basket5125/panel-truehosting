<?php

namespace App\Http\Controllers;

use App\Contracts\GamePanelClient;
use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Location;
use App\Models\Pterodactyl\Node;
use App\Models\Product;
use App\Models\User;
use App\Notifications\DynamicNotification;
use App\Settings\PterodactylSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Role;

class ProductController extends Controller
{
    private $pterodactyl;

    public function __construct(PterodactylSettings $ptero_settings)
    {
        $this->pterodactyl = app(GamePanelClient::class);
    }

    /**
     * @description get product locations based on selected egg
     *
     * @param  Request  $request
     * @param  Egg  $egg
     * @return Collection|JsonResponse
     */
    public function getNodesBasedOnEgg(Request $request, Egg $egg)
    {
        if (is_null($egg->id)) {
            return response()->json('Egg ID is required', '400');
        }

        //get products that include this egg
        $products = Product::query()
            ->with('nodes')
            ->where('disabled', '=', false)
            ->whereHas('eggs', function (Builder $builder) use ($egg) {
                $builder->where('id', '=', $egg->id);
            })->get();

        $nodes = collect();

        //filter unique nodes
        $products->each(function (Product $product) use ($nodes) {
            $product->nodes->each(function (Node $node) use ($nodes) {
                if (! $nodes->contains('id', $node->id) && ! $node->disabled) {
                    $nodes->add($node);
                }
            });
        });

        return $nodes;
    }

    /**
     * @description get product locations based on selected egg
     *
     * @param  Request  $request
     * @param  Egg  $egg
     * @return Collection|JsonResponse
     */
    public function getLocationsBasedOnEgg(Request $request, Egg $egg)
    {
        $nodes = $this->getNodesBasedOnEgg($request, $egg);
        foreach ($nodes as $key => $node) {
            // Only drop a node when the panel actually says it is full.
            // Unknown capacity must not hide every location.
            $capacity = $this->pterodactyl->getNodeCapacity($node->id);

            if ($capacity !== null
                && ($capacity['used_memory'] >= $capacity['memory'] || $capacity['used_disk'] >= $capacity['disk'])) {
                $nodes->forget($key);
            }
        }
        $locations = collect();

        //locations
        $nodes->each(function (Node $node) use ($nodes, $locations) {
            /** @var Location $location */
            $location = $node->location;

            if (! $locations->contains('id', $location->id)) {
                $nodeIds = $nodes->map(function ($node) {
                    return $node->id;
                });

                $location->nodes = $location->nodes()
                    ->whereIn('id', $nodeIds)
                    ->get();

                $locations->add($location);
            }
        });

        if($locations->isEmpty()){
            // Rate limit the node full notification to 1 attempt per 30 minutes
            RateLimiter::attempt(
                key: 'nodes-full-warning',
                maxAttempts: 2,
                callback: function() {
                    // get admin role and check users
                    $users = User::permission("errors.view")->get();
                    if($users){
                        Notification::send($users,new DynamicNotification(['mail'],[],
                            mail: (new MailMessage)->subject('Attention! All of the nodes are full!')->greeting('Attention!')->line('All nodes are full, please add more nodes')));

                    }else{
                        Log::warning("There are no nodes at all - Users couldnt be notified");
                    }
                 },
                decaySeconds: 5
            );
        }

        return $locations;
    }

    /**
     * @param  Int $location
     * @param  Egg  $egg
     * @return Collection|JsonResponse
     */
    public function getProductsBasedOnLocation(Egg $egg, int $location)
    {
        if (is_null($egg->id) || is_null($location)) {
            return response()->json('Location and Egg ID are required', 400);
        }

        $user = Auth::user();
        $products = Product::query()
            ->where('disabled', false)
            ->whereHas('nodes', function (Builder $builder) use ($location) {
                $builder->where('location_id', $location);
            })
            ->whereHas('eggs', function (Builder $builder) use ($egg) {
                $builder->where('id', $egg->id);
            })
            ->with(['nodes' => function ($query) use ($location) {
                $query->where('location_id', $location);
            }])
            ->withCount(['servers' => function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->get();

        // Check if the product fits in at least one node
        foreach ($products as $product) {
            $product->doesNotFit = true;

            foreach ($product->nodes as $node) {
                $capacity = $this->pterodactyl->getNodeCapacity($node->id);

                // Unknown capacity: assume the node can take it rather than
                // marking every product as not fitting.
                if ($capacity === null) {
                    $product->doesNotFit = false;
                    break;
                }

                $availableMemory = $capacity['memory'] - $capacity['used_memory'];
                $availableDisk = $capacity['disk'] - $capacity['used_disk'];

                // If the product fits in this node, mark it as fitting and break out of the loop
                if ($product->memory <= $availableMemory && $product->disk <= $availableDisk) {
                    $product->doesNotFit = false;
                    break;
                }
            }
        }

        return $products;
    }
}
