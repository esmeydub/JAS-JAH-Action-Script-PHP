<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

/**
 * Contrato opcional. La verificación WebAuthn concreta debe vivir en un paquete
 * adaptador auditado; JAS no implementa criptografía WebAuthn parcial.
 */
interface WebAuthnAdapter
{
    public function registrationOptions(string $userId, string $username): array;

    /** @return array{credential_id:string,public_key:string,sign_count:int,transports:list<string>} */
    public function verifyRegistration(string $userId, array $response): array;

    public function authenticationOptions(string $userId, array $credentialIds): array;

    /** @return array{credential_id:string,sign_count:int} */
    public function verifyAuthentication(string $userId, array $response): array;
}
