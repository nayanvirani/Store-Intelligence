<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Railway pre-deploy step: run migrations before the new release takes traffic.
 */
class Deploy extends Command
{
    protected $signature = 'si:deploy';

    protected $description = 'Run migrations before a deploy goes live';

    public function handle(): int
    {
        return $this->call('migrate', ['--force' => true]);
    }
}
