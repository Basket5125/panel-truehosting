<?php

namespace App\Contracts;

use App\Models\Pterodactyl\Egg;
use App\Models\Pterodactyl\Nest;
use App\Models\Pterodactyl\Node;
use App\Models\Product;
use App\Models\Server;

/**
 * The game panel CtrlPanel provisions servers on.
 *
 * Implementations must return Pterodactyl-shaped payloads — collections of
 * ['attributes' => [...]] and Illuminate HTTP responses — because the models,
 * controllers and views were written against that shape. The FeatherPanel
 * driver therefore translates its own responses rather than leaking them.
 */
interface GamePanelClient
{
    /** @return array<int, array{attributes: array}> */
    public function getEggs(Nest $nest);

    /** @return array<int, array{attributes: array}> */
    public function getNodes();

    public function getNode($id);

    /** @return array<int, array{attributes: array}> */
    public function getServers();

    /** @return array<int, array{attributes: array}> */
    public function getNests();

    /** @return array<int, array{attributes: array}> */
    public function getLocations();

    public function getFreeAllocationId(Node $node);

    /** @return array<int, array{attributes: array}> */
    public function getFreeAllocations(Node $node);

    /** @return array<int, array{attributes: array}> */
    public function getAllocations(Node $node);

    public function createServer(Server $server, Egg $egg, int $allocationId, mixed $eggVariables = null);

    public function getServerByExternalId(string $externalId);

    public function suspendServer(Server $server);

    public function unSuspendServer(Server $server);

    public function getUser(int $panelUserId);

    public function updateUser(int $panelUserId, array $data);

    public function getServerAttributes(?int $panelServerId, bool $deleteOn404 = false);

    public function updateServer(Server $server, Product $product);

    public function updateServerBuild(string $panelServerId, int $allocationId, Product $product);

    public function updateServerOwner(Server $server, int $userId);

    public function updateServerDetails(Server $server, array $data);

    public function powerAction(Server $server, $action);

    public function getClientUser();

    public function checkNodeResources(Node $node, int $requireMemory, int $requireDisk);

    /**
     * Capacity and current usage of a node, already adjusted for
     * overallocation.
     *
     * Returns null when the panel does not expose node capacity at all — in
     * that case callers must treat capacity as unknown rather than as zero.
     *
     * @return array{memory: float, disk: float, used_memory: float, used_disk: float}|null
     */
    public function getNodeCapacity($nodeId): ?array;

    /*
     * The operations below used to be performed by reaching into the client's
     * underlying HTTP object with hard-coded Pterodactyl paths. They are part
     * of the contract so every driver can implement them.
     */

    public function createUser(array $data);

    public function deleteUser(int $panelUserId);

    public function deleteServer(int $panelServerId);

    public function getServerById(int $panelServerId);

    /** Human-readable name of the panel this driver talks to. */
    public function panelName(): string;

    /** Base URL of the panel, used for "manage server" links. */
    public function panelUrl(): string;
}
