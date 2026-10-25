<?php

namespace App\Services;

use App\Models\Pterodactyl\Egg;
use Illuminate\Support\Facades\Validator;

/**
 * The egg settings a customer is allowed to see and change, such as the game
 * version, and the one safe way to accept them back.
 *
 * Shared by every page that creates a server — the ordinary one, the
 * configurable plans and the trial — so there is a single answer to "what may
 * this customer set" and no way for the three to drift apart.
 */
class EggVariables
{
    /**
     * What to put in front of the customer for this egg.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCustomer(Egg $egg): array
    {
        return $this->editable($egg)
            ->map(fn ($variable) => [
                'name' => $variable['name'] ?? $variable['env_variable'],
                'env_variable' => $variable['env_variable'],
                'description' => $variable['description'] ?? '',
                'default_value' => (string) ($variable['default_value'] ?? ''),
                'required' => str_contains((string) ($variable['rules'] ?? ''), 'required'),
                // A fixed set of values becomes a list instead of a text box.
                'options' => $this->ruleOptions((string) ($variable['rules'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Check what came back against the rules stored with the egg.
     *
     * The rules come from our own copy of the egg and never from the request:
     * a crafted payload must not be able to relax its own validation, and a
     * variable the egg does not expose to customers is dropped outright.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(Egg $egg, array $submitted): array
    {
        $allowed = $this->editable($egg)->keyBy('env_variable');

        $values = [];
        $rules = [];
        $names = [];

        foreach ($allowed as $key => $variable) {
            // A form field can be sent as an array or an object just as easily
            // as a string; anything that is not a plain value falls back to the
            // egg's default rather than being cast to the word "Array".
            $sent = $submitted[$key] ?? null;
            $usable = array_key_exists($key, $submitted)
                && (is_scalar($sent) || $sent === null);

            $values[$key] = $usable
                ? (string) $sent
                : (string) ($variable['default_value'] ?? '');
            $rules[$key] = $variable['rules'] ?? 'nullable';
            $names[$key] = $variable['name'] ?? $key;
        }

        if ($values === []) {
            return [];
        }

        $validator = Validator::make($values, $rules);
        $validator->setAttributeNames($names);
        $validator->validate();

        return $values;
    }

    /**
     * The same, ready to hand to the creation service, or null when the egg
     * offers the customer nothing to set.
     *
     * @param  array<string, mixed>  $submitted
     */
    public function validatedJson(Egg $egg, array $submitted): ?string
    {
        $values = $this->validate($egg, $submitted);

        return $values === [] ? null : json_encode($values);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function editable(Egg $egg)
    {
        return collect((array) $egg->environment)
            ->filter(fn ($variable) => ($variable['user_viewable'] ?? false)
                && ($variable['user_editable'] ?? false)
                && !empty($variable['env_variable']));
    }

    /**
     * Values an `in:` rule allows, if the rule has one.
     *
     * @return array<int, string>
     */
    private function ruleOptions(string $rules): array
    {
        if (!preg_match('/(?:^|\|)in:([^|]+)/', $rules, $match)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $match[1]))));
    }
}
