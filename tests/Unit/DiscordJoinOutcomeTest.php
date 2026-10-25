<?php

namespace Tests\Unit;

use App\Http\Controllers\Auth\SocialiteController;
use Tests\TestCase;

/**
 * Linking a Discord account that is already in the server used to come back as
 * "Failed to join discord server!", even though the link itself had worked.
 * Discord says so with a 204, which is a success status, so the difference has
 * to be read from the exact code and not from a pass/fail check.
 */
class DiscordJoinOutcomeTest extends TestCase
{
    public function test_a_created_member_counts_as_joined(): void
    {
        $this->assertSame('joined', SocialiteController::joinOutcome(201));
    }

    public function test_an_existing_member_is_not_a_failure(): void
    {
        $this->assertSame('already', SocialiteController::joinOutcome(204));
    }

    public function test_other_success_codes_count_as_joined(): void
    {
        $this->assertSame('joined', SocialiteController::joinOutcome(200));
    }

    /**
     * @dataProvider refusals
     */
    public function test_a_refusal_is_a_failure(int $status): void
    {
        $this->assertSame('failed', SocialiteController::joinOutcome($status));
    }

    /** @return array<string, array{0: int}> */
    public static function refusals(): array
    {
        return [
            'missing access' => [403],
            'unknown guild' => [404],
            'bad token' => [401],
            'rate limited' => [429],
            'discord is down' => [500],
        ];
    }
}
