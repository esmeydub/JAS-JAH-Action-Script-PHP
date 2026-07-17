# Identidad institucional de JAS

`InstitutionalIdentityService` es el proveedor oficial de identidad para sistemas nuevos. Sustituye el almacenamiento monolítico de `AuthStore` por colecciones tipadas, cifradas y auditables en DataCore.

`AuthStore` permanece temporalmente para compatibilidad. Implementa `IdentityProvider`, pero no satisface el perfil institucional y no debe usarse en aplicaciones nuevas.

## Modelo DataCore

| Colección | Responsabilidad | Campos protegidos |
|---|---|---|
| `identity_users` | Usuario, contraseña, estado y MFA | nombre, hash de contraseña, secreto TOTP y códigos de recuperación |
| `identity_roles` | Permisos y separación de funciones | permisos y roles incompatibles |
| `identity_assignments` | Roles permanentes, temporales y delegados | usuario, rol y delegante |
| `identity_sessions` | Sesiones y dispositivos | usuario, dispositivo y etiqueta |
| `identity_mfa_enrollments` | Alta TOTP temporal | usuario y secreto |
| `identity_mfa_challenges` | Desafío previo a sesión | usuario y dispositivo |
| `identity_service_credentials` | Credenciales rotables | servicio y hash secreto |

Los nombres de usuario y hashes de búsqueda permanecen visibles únicamente donde se requieren índices exactos. No se almacenan tokens de sesión: el identificador físico es un HMAC con pepper institucional. Contraseñas y códigos de recuperación usan `password_hash`; los secretos de servicio usan HMAC-SHA-512 y se muestran una sola vez.

## Configuración

```php
<?php

use Jah\DataCore\DataCoreDatabase;
use Jah\DataCore\DataCoreTurbo;
use Jah\JAS\Persistence\AuditJournal;
use Jah\JAS\Security\DualControlStore;
use Jah\JAS\Security\InstitutionalIdentityService;
use Jah\JAS\Type\TypeRegistry;

$types = new TypeRegistry();
InstitutionalIdentityService::defineTypes($types);

$database = new DataCoreDatabase(
    new DataCoreTurbo(__DIR__ . '/runtime/identity-storage'),
    $types,
    __DIR__ . '/runtime/identity',
    $masterKey,
);
InstitutionalIdentityService::configureDatabase($database);

$identity = new InstitutionalIdentityService(
    $database,
    new AuditJournal(__DIR__ . '/runtime/identity-audit'),
    new DualControlStore(__DIR__ . '/runtime/identity-approvals'),
    $institutionalPepper,
);
```

El master key y el pepper deben obtenerse de custodia externa al repositorio, ser distintos y tener al menos 32 bytes.

## Autenticación y MFA

1. `login()` valida contraseña y política de bloqueo.
2. Sin MFA devuelve un token aleatorio de sesión.
3. Con MFA devuelve un desafío de cinco minutos, nunca una sesión provisional.
4. `completeMfa()` acepta TOTP con ventana limitada o un código de recuperación.
5. Cada recuperación se elimina atómicamente después de su primer uso.
6. Activar MFA revoca todas las sesiones creadas antes del alta.
7. Un desafío usado, vencido o desconocido es rechazado.

El alta se divide en `previewTotpSecret()` y `confirmTotp()`. El secreto provisional vence en diez minutos. La aplicación debe mostrarlo como QR/URI desde su propia capa de presentación; JAS no genera HTML o imágenes dentro del núcleo de identidad.

## Roles, permisos y separación de funciones

Los roles contienen permisos exactos o prefijos terminados en `.*`. Las asignaciones pueden tener expiración y delegante. La autorización calcula los roles activos en cada consulta: revocaciones, expiraciones y cambios de permisos no requieren recrear una sesión.

Un rol no puede coexistir con cualquiera declarado incompatible por él o por un rol ya asignado. Esta comprobación evita combinaciones como operador/auditor o solicitante/aprobador.

`AuthMiddleware` detecta `AuthorizationProvider` y consulta permisos dinámicos de DataCore. Para el proveedor heredado conserva `RolePolicy` como compatibilidad.

## Operaciones críticas

`requestCriticalAction()` y `approveCriticalAction()` exigen:

- sesión vigente;
- MFA verificado para ambos actores;
- permisos separados `<acción>.request` y `<acción>.approve`;
- identidades distintas, impuesto por `DualControlStore`;
- misma acción, request ID y huella de datos al consumir;
- autorización de un solo uso.

## Sesiones, dispositivos y servicios

`sessions()` muestra dispositivos activos y revocados del usuario autenticado. `revokeSession()` sólo permite revocar una sesión del mismo propietario. La expiración se evalúa en cada identidad.

Las credenciales de servicio tienen ID público y secreto mostrado una vez. Rotar incrementa la versión e invalida inmediatamente el secreto anterior. Una credencial vencida o inactiva nunca autentica.

## WebAuthn y federación

JAS publica contratos opcionales:

- `WebAuthnAdapter` para registro y verificación de passkeys;
- `FederatedIdentityAdapter` para OIDC, SAML o LDAP.

Las implementaciones concretas deben vivir fuera del núcleo y usar bibliotecas auditadas. JAS deliberadamente no implementa una versión parcial de WebAuthn, SAML u OIDC, porque hacerlo produciría una falsa garantía criptográfica.

## Verificación

```bash
php tests/test_jas_identity.php
```

La prueba cubre cifrado físico, permisos dinámicos, bloqueo, MFA, recuperación de un uso, replay, vencimiento, dispositivos, separación de funciones, delegación, doble control, rotación de credenciales y cadena de auditoría.
