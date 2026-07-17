<?php

declare(strict_types=1);

namespace Jah\JAS\Security;

/** Implementaciones OIDC, SAML o LDAP se distribuyen fuera del núcleo JAS. */
interface FederatedIdentityAdapter
{
    public function protocol(): string;

    /** @return array{issuer:string,subject:string,username:string,claims:array} */
    public function authenticate(array $assertion): array;
}
