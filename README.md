# wassimo-identity

Authentication and authorization service for the Wassimo food-delivery platform.

**Stack:** Laravel 13 · PHP 8.3 · MySQL 8.4 · Redis 7 · Sanctum 4 · Spatie Permission 6

---

## Responsibility

This service owns:

- user accounts (`users` table — email + hashed password only)
- bearer tokens (`personal_access_tokens` — SHA-256 hashed, 24h expiry)
- roles and permissions (Spatie — 4 roles, 26 permissions across 6 domains)

It does not own delivery addresses, order history, payment methods, or any
domain data. Those belong to the services that use them.

---

## Ports

| Service | Host port | Container port |
|---|---|---|
| Identity API (dev) | 9075 | 8000 |
| Identity API (prod) | 9076 | 8000 |
| MySQL | 3306 | 3306 |
| Redis | 6380 | 6379 |

---

## Quick start

```bash
# 1. Create the shared Docker network (once)
docker network create wassimo-network

# 2. Start MySQL + Redis + identity
docker compose up -d

# 3. Run migrations and seed roles/permissions
docker compose exec identity php artisan migrate --force
docker compose exec identity php artisan db:seed

# 4. Verify
curl localhost:9075/health          # {"status":"ok"}
curl localhost:9075/api/ready       # {"status":"ok"} when MySQL + Redis are up
```

---

## Environment variables

Copy `.env.example` to `.env` and fill in:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=identity_db
DB_USERNAME=wassimo
DB_PASSWORD=secret

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

SANCTUM_EXPIRATION=1440
AUTH_GUARD=api
```

Inside Docker the host values are overridden by the compose environment block
(`DB_HOST=mysql`, `REDIS_HOST=redis`).

---

## API

### Public endpoints

```
POST /api/auth/register   {email, password}
POST /api/auth/login      {email, password, [device_name]}
```

### Protected endpoints (Bearer token required)

```
GET    /api/auth/me
POST   /api/auth/logout
POST   /api/auth/logout-all
GET    /api/auth/tokens
DELETE /api/auth/tokens/{id}
POST   /api/auth/password/change   {current_password, password}
```

### Admin endpoints (Bearer token + permission required)

```
GET    /api/roles
GET    /api/permissions
GET    /api/users                  ?q=&role=&page=&per_page=
POST   /api/users                  {email, password, [roles[]]}
GET    /api/users/{id}
DELETE /api/users/{id}
PUT    /api/users/{id}/roles        {roles[]}
PUT    /api/users/{id}/permissions  {permissions[]}
```

### Probes

```
GET /health       liveness — always 200 while the process is alive
GET /api/ready    readiness — 200 only when MySQL AND Redis are reachable
```

---

## Response shape

Every endpoint that returns a user uses this shape:

```json
{
  "user": {
    "id": 1,
    "email": "ahmed@example.com",
    "roles": ["customer"],
    "permissions": ["catalog.read", "cart.write", "cart.read", "orders.place"],
    "created_at": "2026-10-02T10:00:00+00:00"
  }
}
```

The login response also includes `token`, `token_type`, and `expires_at`.

---

## Roles and permissions

Four roles, each carrying a permission set:

| Role | Key permissions |
|---|---|
| `customer` | `cart.*`, `orders.place/read/cancel`, `deliveries.read`, `payments.read` |
| `restaurant` | `menu.write`, `restaurant.manage`, `orders.accept/reject/prepare/ready` |
| `courier` | `deliveries.accept/pickup/complete` |
| `admin` | all 26 permissions |

A user can hold multiple roles. `PUT /api/users/{id}/roles` replaces the set
atomically. The effective permission set is the union of all role permissions
plus any direct grants.

Permission names are a public contract. Downstream services (Cart, Order,
Delivery) check the `X-User-Permissions` header the gateway stamps on every
request. Renaming a permission here is a breaking change for those services.

---

## How it integrates with the gateway

The gateway calls `GET /api/auth/me` on every protected request with the
client's Bearer token. On success, it stamps three headers onto the forwarded
request:

```
X-User-Id:           <user id>
X-User-Roles:        <comma-separated roles>
X-User-Permissions:  <comma-separated permissions>
```

Downstream services read these headers. They never call identity directly.

---

## How it works — detailed

See `steps/identity/05-how-it-works/HOW-IT-WORKS.md` for:

- Full request journey diagram (browser → gateway → identity → MySQL/Redis)
- Every file and what it owns
- Database schema with column types
- Token lifecycle (create → validate → revoke → prune)
- Permission data flow and Redis caching
- What the gateway knows vs what it does not
- Failure modes and their responses
- Security properties the design guarantees
- Comparison table vs the Go/Catalog approach

---

## Running artisan commands

```bash
# Inside the running container
docker compose exec identity php artisan migrate --force
docker compose exec identity php artisan db:seed
docker compose exec identity php artisan route:list
docker compose exec identity php artisan queue:failed
docker compose exec identity php artisan sanctum:prune-expired --hours=24
docker compose exec identity php artisan tinker

# Locally (requires PHP + MySQL + Redis running)
php artisan serve --port=8000
```

---

## Enhancement ideas

These are not planned for the current slice but are worth building in sequence
when the core auth is working.

### Tier 1 — Security hardening (highest value, lowest risk)

**Rate limiting on credential routes** (ADR-017 Group A)
Redis-backed throttle on login and register, per-IP and per-email. Without it,
login is a free bcrypt-hash burner. A 5 req/min limit per email + per IP makes
brute-forcing impractical.
```php
RateLimiter::for('login', fn(Request $r) => [
    Limit::perMinute(5)->by('ip:'    . $r->ip()),
    Limit::perMinute(5)->by('email:' . $r->input('email', '')),
]);
```

**Security event log** (ADR-017 Group A)
A `security_events` table recording login success/failure, logout, role change,
and password change. Fields: `user_id`, `event`, `ip`, `user_agent`,
`request_id`, `created_at`. Never log a token, password, or request body.
When a user reports "someone accessed my account", this is the only answer.

**Permission enforcement in UserController**
`Gate::authorize('users.list')` as the first line of each method. Currently
routes are auth-gated but not permission-gated — any authenticated user can
list all users.

### Tier 2 — Account self-service (ADR-017 Group B)

**Password reset**
`POST /auth/password/forgot` → queue a mail → `POST /auth/password/reset`.
Both return 202 for unknown emails (same enumeration rule as login).
Requires `password_reset_tokens` migration and a `Notification` class.

**Email verification**
`GET /auth/email/verify?id=&hash=` + `POST /auth/email/resend`.
Needed before downstream services trust `email_verified_at`.

### Tier 3 — Second factor (ADR-017 Group C)

**TOTP two-factor authentication**
Optional per user. Flow: enable → scan QR → confirm → login returns
`401 {"error":"two_factor_required"}` → `POST /auth/2fa/challenge {code}` → token.
Recovery codes hashed at rest, shown once.
Rate-limiting from Tier 1 must come first — the challenge endpoint is
unauthenticated and takes a 6-digit code (1-in-1,000,000 guess space).

### Tier 4 — Architecture improvements

**Token response cache in the gateway**
The gateway calls `/auth/me` on every protected request. At scale this becomes
the bottleneck. Cache the `/auth/me` response in Redis with a TTL matching
the token expiry. Invalidate on logout. The 24h token expiry makes reasoning
about cache staleness much simpler than "never expire".

**Permission enum**
```php
enum Permission: string {
    case CartWrite   = 'cart.write';
    case OrdersPlace = 'orders.place';
}
$user->can(Permission::CartWrite->value)
```
A typo in a bare string silently denies access forever. An enum catches it at
parse time.

**API Resource classes**
Replace `UserResource::make()` static method with a proper
`Illuminate\Http\Resources\Json\JsonResource` subclass. Adds conditional
field loading and collection wrapping for free.

**Form Request classes**
Move validation out of controllers into `App\Http\Requests\Auth\LoginRequest`,
`RegisterRequest`, etc. Keeps controllers focused on HTTP semantics.

### Tier 5 — Deferred by design

**Refresh tokens** — adds a second token class with its own lifetime, rotation,
and revocation rules. Worth doing once 24h expiry creates visible UX friction.

**TOTP → passkeys migration** — WebAuthn/passkeys are the better long-term
answer but cannot coexist casually with TOTP. Decide one, build it fully.

**Service-to-service authentication** — Slice 8. Separate token type with
`abilities: ['service']` or mTLS. Do not reuse user tokens for service calls.

**Social / OAuth login** — a second trust boundary (provider token → local user).
Not complex, but adds a new attack surface that must be reasoned about separately.

---

## Database access

```bash
# DBeaver or any MySQL client
Host: 127.0.0.1
Port: 3306
Database: identity_db
User: wassimo
Password: secret  (from .env)

# Via artisan tinker
php artisan tinker --execute="
  App\Models\User::with(['roles','permissions'])->find(1)
"

# Redis
redis-cli -p 6380
> select 1
> keys *         # permission cache
> select 0
> LLEN queues:default   # job queue depth
```
