<?php

namespace App\Console\Commands;

use App\Actions\ApiAccess\CreateMicrositeClient;
use App\Actions\ApiAccess\ReplaceMicrositeToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('booking-engine:create-microsite-client {--name=Booking microsite : The name of the client} {--replace : Give the active client a new token; the old one stops at once}')]
#[Description('Create the booking microsite API client, or replace its token, and print the token once')]
class CreateMicrositeClientCommand extends Command
{
    public function handle(CreateMicrositeClient $createClient, ReplaceMicrositeToken $replaceToken): int
    {
        if ($this->option('replace')) {
            $token = $replaceToken();

            if ($token === null) {
                $this->error('There is no active microsite client. Run the command without --replace to create one.');

                return self::FAILURE;
            }
        } else {
            $token = $createClient((string) $this->option('name'));

            if ($token === null) {
                $this->error('An active microsite client already exists. Use --replace to give it a new token.');

                return self::FAILURE;
            }
        }

        $this->line($token);
        $this->info('Store this token now. It is not shown again.');

        return self::SUCCESS;
    }
}
