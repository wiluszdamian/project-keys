<?php

namespace App\Support;

use Illuminate\Support\Str;
use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Weryfikacja passkey tylko z adresu panelu (APP_URL). Lokalnie (APP_ENV=local) także http://localhost,
 * bo biblioteka WebAuthn bez tego wymaga HTTPS.
 */
class PasskeyCeremony extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();
        $factory->setAllowedOrigins([Str::of((string) config('app.url'))->rtrim('/')->toString()]);

        if (app()->environment('local')) {
            $factory->setSecuredRelyingPartyId(['localhost']);
        }

        return $factory;
    }
}
