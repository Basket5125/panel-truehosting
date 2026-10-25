<?php

namespace App\Settings;

use App\Models\Product;
use App\Models\Pterodactyl\Egg;
use Spatie\LaravelSettings\Settings;
use Throwable;

/**
 * A free, time limited server a visitor can try before buying one.
 *
 * Everything about it is the operator's decision: who may take one, what it
 * may be built from, how long it lasts and what happens when the time is up.
 */
class TrialSettings extends Settings
{
    public bool $enabled = false;

    /** What a customer has to have before they may take a trial. */
    public bool $require_email_verification = true;
    public bool $require_discord_verification = false;
    public bool $require_captcha = true;

    public int $duration_hours = 24;

    /** How many times the customer may extend it by another period. */
    public int $max_extensions = 1;

    /** suspend | delete — what happens once the time is up. */
    public string $end_action = 'suspend';

    /** How many trials one account may ever take. */
    public int $per_user_limit = 1;

    /** Product ids a trial may be built from; empty means none are offered. */
    public array $products = [];

    /** Egg ids a trial may run; empty means every egg of the chosen product. */
    public array $eggs = [];

    public static function group(): string
    {
        return 'trial';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getValidations()
    {
        return [
            'enabled' => 'nullable|string',
            'require_email_verification' => 'nullable|string',
            'require_discord_verification' => 'nullable|string',
            'require_captcha' => 'nullable|string',
            'duration_hours' => 'required|integer|min:1|max:8760',
            'max_extensions' => 'required|integer|min:0|max:10',
            'end_action' => 'required|in:suspend,delete',
            'per_user_limit' => 'required|integer|min:1|max:100',
            'products' => 'nullable|array',
            'products.*' => 'string',
            'eggs' => 'nullable|array',
            'eggs.*' => 'string',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getOptionInputData()
    {
        return [
            'category_icon' => 'fas fa-flask',
            'position' => 12,
            'enabled' => [
                'label' => 'Trial Servers Enabled',
                'type' => 'boolean',
                'description' => 'Offer a free, time limited server so people can try the hosting before buying.',
            ],
            'require_email_verification' => [
                'label' => 'Require a verified e-mail',
                'type' => 'boolean',
                'description' => 'Only accounts with a confirmed e-mail address may take a trial.',
            ],
            'require_discord_verification' => [
                'label' => 'Require a linked Discord',
                'type' => 'boolean',
                'description' => 'Only accounts with Discord linked may take a trial.',
            ],
            'require_captcha' => [
                'label' => 'Require the captcha',
                'type' => 'boolean',
                'description' => 'Ask for the captcha configured under General before the trial is created. Has no effect while no captcha is configured.',
            ],
            'duration_hours' => [
                'label' => 'Trial length (hours)',
                'type' => 'number',
                'description' => 'How long a trial server runs before the action below is taken.',
            ],
            'max_extensions' => [
                'label' => 'Extensions allowed',
                'type' => 'number',
                'description' => 'How many times the customer may extend a trial by another period. 0 means it cannot be extended.',
            ],
            'end_action' => [
                'label' => 'When the time is up',
                'type' => 'select',
                'options' => [
                    'suspend' => 'Suspend the server',
                    'delete' => 'Delete the server',
                ],
                'description' => 'Suspending keeps the files, so buying later picks up where the trial left off. Deleting frees the node immediately.',
            ],
            'per_user_limit' => [
                'label' => 'Trials per account',
                'type' => 'number',
                'description' => 'How many trials one account may ever take, including ones already finished.',
            ],
            'products' => [
                'label' => 'Packages offered',
                'type' => 'multiselect',
                'options' => self::productOptions(),
                'description' => 'Which packages a trial may be built from. Nothing selected means trials are not offered.',
            ],
            'eggs' => [
                'label' => 'Software offered',
                'type' => 'multiselect',
                'options' => self::eggOptions(),
                'description' => 'Which software a trial may run. Nothing selected means every egg attached to the chosen package.',
            ],
        ];
    }

    /** @return array<string, string> */
    private static function productOptions(): array
    {
        try {
            return Product::orderBy('name')->pluck('name', 'id')->all();
        } catch (Throwable $e) {
            // The settings page is rendered before the tables exist on install.
            return [];
        }
    }

    /** @return array<string, string> */
    private static function eggOptions(): array
    {
        try {
            return Egg::with('nest')->orderBy('name')->get()
                ->mapWithKeys(fn (Egg $egg) => [
                    (string) $egg->id => ($egg->nest?->name ? $egg->nest->name . ' · ' : '') . $egg->name,
                ])
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }
}
