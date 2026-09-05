# mecentral/sdk

Official MeCentral API client for PHP 8.1+. Requires `ext-curl` and `ext-json`; no other dependencies.

```bash
composer require mecentral/sdk
```

```php
use MeCentral\Client;

$mc = new Client(getenv('MECENTRAL_API_KEY'));

// Documents this integration's account owns
$page = $mc->vault->listDocuments(limit: 20);
echo $page['total'];

// File a document into its subject's vault, then wait for the worker
$accepted = $mc->vault->depositOnBehalf([
    'subject_email' => 'student@example.edu',
    'subject_role'  => 'student',
    'filename'      => 'bls-card.pdf',
    'mime_type'     => 'application/pdf',
    'content'       => file_get_contents('bls-card.pdf'),
    'grants'        => [['user' => 'coordinator@school.edu', 'scope' => 'co_owner']],
]);
$result = $mc->vault->waitForDeposit($accepted['job_id']);
if (!empty($result['grant_errors'])) {
    error_log('some grants did not apply: ' . json_encode($result['grant_errors']));
}
```

## Authentication

Every request sends your key in `X-API-Key`. Keys are `mec_sbx_…` (sandbox) or `mec_prd_…` (production); both talk to the same host. Sandbox keys can be minted without a console login for proof-of-concept builds — see `Client::provisionSandbox`.

```php
$mc = new Client('mec_sbx_…', [
    'base_url'    => 'https://api.mecentral.org/v1', // default
    'timeout'     => 30,                             // default, seconds
    'max_retries' => 2,                              // default
]);
```

The People Graph endpoints act on a **member's** graph and need a member connection token as well:

```php
$conn = $mc->connections->request('member@example.com');
// …the member reads the one-time code MeCentral sent them…
$verified = $mc->connections->verify($conn['connection_id'], '123456');

$member = $mc->withConnection($verified['token']);
$created = $member->people->create([
    'full_name'    => 'Amelia Glisson',
    'relationship' => 'biological_child',
    'facts'        => [['key' => 'school', 'value' => 'Parker Elementary']],
]);
```

## Surface

| Property | Methods | Scope |
|---|---|---|
| `vault` | `listDocuments`, `getDocument`, `downloadDocument`, `depositOnBehalf`, `depositStatus`, `waitForDeposit`, `recallDeposit`, `updateSubject`, `revokeSubjectBinding` | `vault.read`, `custody.read` (stream), `vault.deposit_on_behalf` |
| `custody` | `grant`, `revoke`, `replace`, `dispute`, `assertEvent`, `listEvents` | `custody.grant`, `custody.replace`, `custody.events` |
| `connections` | `request`, `verify` | any key |
| `people` | `list`, `create`, `get`, `update`, `remove`, `listFacts`, `confirmFacts` | `people.write` + connection token |
| `webhooks` | `list`, `create`, `get`, `update`, `delete`, `eventTypes` | `webhooks.manage` |
| `integrations` | `listSigningCertificates`, `registerSigningCertificate`, `revokeSigningCertificate` | `vault.deposit_on_behalf` |
| `\MeCentral\Webhooks` (static) | `constructEvent`, `verify`, `sign` | — |

Array keys are the REST API's own (`snake_case`), so the API reference at `/developers/api` applies unchanged. Responses are returned as the API sends them, `success` envelope included.

## Webhooks

Deliveries are signed with HMAC-SHA256 over `"<timestamp>.<raw body>"` using the secret returned **once** when you register the endpoint. Verify against the raw body, never a re-serialised object.

```php
use MeCentral\Webhooks;
use MeCentral\Exception\SignatureVerificationException;

// Vanilla PHP: headers are read from the request when null is passed
try {
    $event = Webhooks::constructEvent(file_get_contents('php://input'), null, getenv('MECENTRAL_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}
if ($event['type'] === 'deposit.completed') {
    // $event['data']['document_id'], $event['data']['grant_errors'], …
}
http_response_code(200);
```

Laravel: `Webhooks::constructEvent($request->getContent(), $request->headers->all(), $secret)`. Symfony: the same with `$request->headers->all()`.

Deliveries are best-effort and not retried. Treat them as a nudge and reconcile against `vault->depositStatus`, which is the source of truth.

## Errors

All failures throw `MeCentral\Exception\MeCentralException` or a subclass with `$status`, `getMessage()`, `$apiCode`, `$retryAfter`, and the decoded `$body`.

| Class | Status |
|---|---|
| `AuthenticationException` | 401 |
| `PermissionException` | 403 (scope, IP, or endpoint allowlist) |
| `NotFoundException` | 404 (never distinguishes "not yours" from "never existed") |
| `ValidationException` | 400 / 422 |
| `RateLimitException` | 429, `$retryAfter` in seconds |
| `ServerException` | 5xx |
| `ConnectionException` | no HTTP response |
| `SignatureVerificationException` | webhook check failed |
| `DepositTimeoutException` | `waitForDeposit` ran out of time |

## Retries

`429` and `503` are retried for every method with exponential backoff (honouring `Retry-After`). `502`, `504`, and transport failures are retried only for `GET` and `DELETE`, because a `POST` that timed out may already have been processed. Set `'max_retries' => 0` to disable.

## Sandbox provisioning

```php
$issued = Client::provisionSandbox(getenv('INTEGRATION_PROVISION_TOKEN'), [
    'slug'     => 'myapp.example.com',
    'scopes'   => ['vault.deposit_on_behalf', 'custody.grant', 'webhooks.manage'],
    'ttl_days' => 90,
]);
$issued['key']['key']; // shown here and nowhere else
```

## Using from source

Without Packagist, point Composer at the directory:

```json
{
  "repositories": [{ "type": "path", "url": "../mecentral-developers/sdks/php" }],
  "require": { "mecentral/sdk": "*" }
}
```
