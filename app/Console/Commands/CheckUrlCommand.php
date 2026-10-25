<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * Why a signed link stops validating.
 *
 * A signature covers the whole absolute URL, so the address written into an
 * e-mail and the address the request arrives on must agree down to the scheme.
 * Behind a proxy they often do not, and the only symptom is "Invalid
 * signature" on a link that looks perfectly fine.
 */
class CheckUrlCommand extends Command
{
    protected $signature = 'cp:url:check';

    protected $description = 'Check the settings that signed links depend on';

    public function handle(): int
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $proxies = config('app.trusted_proxies');
        $cached = file_exists($this->laravel->getCachedConfigPath());

        $this->newLine();
        $this->line(' APP_URL          <info>' . ($appUrl ?: '(not set)') . '</info>');
        $this->line(' Scheme forced    ' . ($scheme === 'https' ? '<info>https</info>' : '<comment>no</comment>'));
        $this->line(' Trusted proxies  ' . ($proxies ? '<info>' . $proxies . '</info>' : '<error>none</error>'));
        $this->line(' Config cached    ' . ($cached ? 'yes' : 'no'));
        $this->newLine();

        $problems = 0;

        if ($appUrl === '' || str_contains($appUrl, 'localhost')) {
            $this->line(' <error>FAIL</error> APP_URL still points at localhost. Signed links are built from it,');
            $this->line('        so it has to be the address people actually open.');
            $problems++;
        }

        if (rtrim($appUrl, '/') !== $appUrl) {
            $this->line(' <comment>WARN</comment> APP_URL ends with a slash. Drop it.');
            $problems++;
        }

        if ($scheme === 'https' && empty($proxies)) {
            $this->line(' <error>FAIL</error> APP_URL is https but no proxy is trusted.');
            $this->line('        A tunnel or reverse proxy reaches the panel over plain HTTP, so the');
            $this->line('        request is seen as http:// while the link was signed as https://, and');
            $this->line('        every signature is rejected. Set TRUSTED_PROXIES=* in .env.');
            $problems++;
        }

        if ($scheme !== 'https' && !empty($proxies)) {
            $this->line(' <comment>WARN</comment> A proxy is trusted but APP_URL is not https. If visitors reach the');
            $this->line('        panel over https, set APP_URL to the https address.');
            $problems++;
        }

        // What a verification link looks like right now.
        $sample = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => 1,
            'hash' => sha1('check@example.test'),
        ]);

        $this->line(' Example link     ' . $sample);
        $this->newLine();

        if ($problems > 0) {
            $this->warn('Fix the above, then run: php artisan config:clear');

            return self::FAILURE;
        }

        $this->info('The link above must begin with exactly the address your visitors use.');

        return self::SUCCESS;
    }
}
