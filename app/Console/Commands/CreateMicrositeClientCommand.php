<?php

namespace App\Console\Commands;

use App\Actions\ApiAccess\CreateMicrositeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('booking-engine:create-microsite-client {--name=Booking microsite : The name of the client}')]
#[Description('Create the booking microsite API client and print its token once')]
class CreateMicrositeClientCommand extends Command
{
    public function handle(CreateMicrositeClient $createClient): int
    {
        $token = $createClient((string) $this->option('name'));

        if ($token === null) {
            $this->error('An active microsite client already exists.');

            return self::FAILURE;
        }

        $this->line($token);
        $this->info('Store this token now. It is not shown again.');

        return self::SUCCESS;
    }
}
