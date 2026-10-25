<?php

namespace Tests\Unit;

use App\Services\ServerUpgradeService;
use App\Models\Server;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Restarting is how new limits take effect, not part of the upgrade
 * succeeding. A panel that refuses the restart has not undone anything, so the
 * customer used to be shown "Failed to restart server: Invalid API key" for an
 * upgrade that had in fact been applied — and, worse, the new product was
 * never recorded because the exception skipped that step.
 */
class UpgradeRestartTest extends TestCase
{
    private function restartResult($responseOrThrow): bool
    {
        $panel = Mockery::mock(\App\Contracts\GamePanelClient::class);

        if ($responseOrThrow instanceof \Throwable) {
            $panel->shouldReceive('powerAction')->andThrow($responseOrThrow);
        } else {
            $panel->shouldReceive('powerAction')->andReturn($responseOrThrow);
        }

        $this->app->instance(\App\Contracts\GamePanelClient::class, $panel);

        $service = new ServerUpgradeService();
        $method = new ReflectionMethod($service, 'tryRestart');
        $method->setAccessible(true);

        return $method->invoke($service, new Server(['name' => 'test']));
    }

    public function test_a_restart_that_works_is_reported_as_done(): void
    {
        $this->assertTrue($this->restartResult(new Response(new Psr7Response(204))));
    }

    /**
     * The body carries no "errors" key at all here, which is what the old code
     * read blindly.
     */
    public function test_a_refusal_is_reported_without_throwing(): void
    {
        $refusal = new Response(new Psr7Response(401, ['Content-Type' => 'application/json'],
            json_encode(['success' => false, 'message' => 'Invalid API key'])));

        $this->assertFalse($this->restartResult($refusal));
    }

    public function test_an_unreachable_panel_is_reported_without_throwing(): void
    {
        $this->assertFalse($this->restartResult(new \Exception('connection refused')));
    }

    public function test_the_service_starts_out_assuming_a_restart_happened(): void
    {
        $this->assertTrue((new ServerUpgradeService())->restarted);
    }
}
