# Wisp 🌬️
> A Cloudflare Workers style micro-framework for PHP 7.4 to 8.x.

Wisp is a lightweight, ultra-fast PHP micro-framework designed for developers who love the serverless, callback-centric developer experience of Cloudflare Workers and Hono. 

It features an **onion-style middleware pipeline**, a **reflective auto-wiring Dependency Injection container**, and support for **clean URLs** right out of the box, with **zero external dependencies required** to start.

---

## Key Features

- ⚡ **Workers Style Syntax**: Route requests using `$app->get()`, `$app->post()`, and return standard `Response` objects.
- 📦 **Route Groups**: Group endpoints with shared prefixes and middlewares (`$app->group('/api/v1', ...)`), with full nesting support.
- 🧪 **Pure In-Memory Testing**: Test your routes without running a web server via `$app->handle(Request::create(...))`.
- 🚦 **RFC 405 Method Not Allowed**: Automatically distinguishes between 404 (route missing) and 405 (method disallowed) with accurate `Allow` headers.
- 🧅 **Onion Middleware**: Supports global, path-prefix (wildcard), inline, and route-specific middleware pipelines.
- 🧬 **Reflective Auto-Wiring**: Simply put your classes in the `services/` directory, and Wisp will automatically resolve constructor dependencies and instantiate them on demand.
- ⚙️ **Decoupled Configs**: Keep `public/index.php` pristine by separating global configurations into `config/`.
- 📁 **Clean URLs & Wildcards**: Front controller setup with rewrite rules for Apache/XAMPP (`.htaccess`), named parameters (`:id`), and wildcard catch-all segments (`*path`).
- 🛠️ **Zero Dependency Fallback**: Works instantly using a robust fallback autoloader and `.env` parser if Composer is not yet installed.
- 🌐 **Shared Hosting Friendly**: Designed to run seamlessly on standard Apache/Nginx shared hosting environments using standard `.htaccess` URL rewriting—no terminal access or Composer execution required on the production server.

---

## Directory Structure

```text
api_php/
├── composer.json               # Composer configuration and PSR-4 settings
├── LICENSE                     # MIT License
├── README.md                   # Project documentation
├── .gitignore                  # Git untracked files
├── .env.example                # Template database credentials
├── .env                        # Active environment variables (git-ignored)
├── router.php                  # Routing script for PHP built-in server
│
├── config/                     # Decoupled global registrations
│   ├── services.php            # Manual service container registrations
│   └── middlewares.php         # Global middleware registrations
│
├── public/                     # Public web root
│   ├── index.php               # Entrypoint & bootstrap front controller
│   └── .htaccess               # Apache rewrite rules for clean URLs
│
├── src/                        # Wisp Core Classes (Namespace: Wisp\)
│   ├── App.php                 # Core router, DI container, autowiring, & middleware pipeline
│   ├── Request.php             # Request model wrapper
│   ├── Response.php            # Response model wrapper
│   └── DB.php                  # Database wrapper around PDO (MySQL, PostgreSQL, SQLite)
│
├── middlewares/                # Custom route/global middleware classes
│   ├── AuthMiddleware.php      # Example: Bearer token validation middleware
│   └── RateLimitMiddleware.php # Example: Rate limit middleware class
│
├── services/                   # Custom service classes (Auto-wired)
│   └── TranslateService.php    # Example: Service class with automatic DB injection
│
├── routes/                     # Dynamic route files (Auto-loaded)
│   ├── version.php             # Example: Version check endpoint route
│   └── license.php             # Example: License and translation endpoint routes
│
└── plugins/                    # Optional, downloadable add-ons (empty by default)
    └── <name>/<name>.php       # Drop-in convention picked up automatically at boot
```

---

## Getting Started

### 1. Requirements
- PHP 7.4 or higher (fully compatible with PHP 8.0, 8.1, 8.2, 8.3+)
- MySQL, PostgreSQL, or SQLite (if using database functionality)
- Composer (optional)

### 2. Setup
Clone the repository, create your `.env` configuration file from the template, and configure your database settings:
```bash
cp .env.example .env
```

Set `DB_CONNECTION` to choose the driver (`mysql`, `pgsql`, or `sqlite`):
```dotenv
# MySQL (default)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=my_db
DB_USERNAME=root
DB_PASSWORD=

# PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=my_db
DB_USERNAME=postgres
DB_PASSWORD=

# SQLite (DB_DATABASE is a file path, or ":memory:")
DB_CONNECTION=sqlite
DB_DATABASE=storage/database.sqlite
```

If you wish to use Composer packages:
```bash
composer install
```
*(Wisp will automatically fall back to its internal PSR-4 autoloader if composer is not run).*

### 3. Local Development Server
To launch the PHP built-in web server with clean URLs supported:
```bash
php -S localhost:8000 router.php
```

---

## Code Examples

### 1. Simple Routing & JSON Response
Create custom routes in `routes/version.php`:
```php
<?php
global $app;

$app->get("/version", function($req) {
    return Response::json([
        "version" => "1.2.0"
    ]);
});
```

### 2. Class-Based Middleware
Define middlewares in `middlewares/AuthMiddleware.php`:
```php
<?php

class AuthMiddleware {
    public function handle($req, $next, $env) {
        $secret = $_ENV['API_SECRET_KEY'] ?? '';
        $auth = $req->header('Authorization') ?? '';

        if ($secret === '' || !hash_equals('Bearer ' . $secret, $auth)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($req);
    }
}
```

Apply the middleware directly to specific routes in your route files:
```php
$app->post("/license", AuthMiddleware::class, function($req) {
    return Response::json(["success" => true]);
});
```

### 3. Auto-Wired Services (Dependency Injection)
Create custom services in the `services/` directory. Wisp's container supports **Type-Hinted Injection** (`PDO`, service classes, `App`), parameter names, and default values:
```php
<?php

class TranslateService {
    private PDO $db;

    // Auto-wired by type-hint (PDO) or parameter name ($db)!
    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function translate(string $text): string {
        return "Translated: " . strtoupper($text);
    }
}
```

You can also bind services explicitly in `config/services.php`:
```php
// Singleton (cached instance)
$app->singleton('mailer', fn() => new MailerService());

// Transient (new instance on every resolve)
$app->bind('uuid', fn() => new UuidGenerator());

// Pre-existing instance
$app->instance('config', ['debug' => true]);
```

Call the service directly in your routes using `$env->{serviceName}`:
```php
$app->get("/translate", function($req, $env) {
    $text = $req->query['text'] ?? '';
    
    // Auto-wired and instantiated on-demand
    $result = $env->translateService->translate($text);

    return Response::json([
        "result" => $result
    ]);
});
```

### 4. Error Handling & Debug Mode
Wisp includes comprehensive developer error diagnostics controlled by `APP_DEBUG` in `.env`:

- **Debug Mode (`APP_DEBUG=true`)**:
  - API / JSON requests: Returns full diagnostic details including `exception`, `message`, `file`, `line`, and `trace`.
  - Browser / HTML requests: Renders a sleek, zero-dependency dark-mode error page with source snippet preview (highlighting the exact error line) and full stack trace.
  - Route 404: Returns the missing method/path along with a list of all currently registered routes for easy troubleshooting.
- **Production Mode (`APP_DEBUG=false`)**:
  - Automatically suppresses sensitive server paths, credentials, and stack traces, returning clean standard errors (`{"error": "Internal Server Error"}`).

You can also register a custom global exception handler using `$app->onError()`:
```php
$app->onError(function (\Throwable $e, Request $req) {
    if ($e instanceof InvalidArgumentException) {
        return Response::json([
            'error' => 'Validation Error',
            'message' => $e->getMessage()
        ], 422);
    }
    // Return null to fall back to Wisp's default error handling
    return null;
});
```

### 5. Route Groups & Wildcards
Organize your API endpoints with route prefixes, nested groups, and shared middlewares:
```php
$app->group('/api/v1', AuthMiddleware::class, function($app) {
    $app->get('/users', function($req) {
        return Response::json(['users' => []]);
    });

    // Nested groups
    $app->group('/admin', AdminMiddleware::class, function($app) {
        $app->get('/stats', function() {
            return Response::json(['active_users' => 120]);
        });
    });
});

// Wildcard catch-all route (e.g. static assets or file download)
$app->get('/files/*filepath', function($req) {
    $path = $req->params['filepath']; // e.g. "images/avatar.png"
    return Response::text("Requested file: " . $path);
});
```

### 6. In-Memory Unit Testing
Wisp separates request execution (`$app->handle()`) from output emission (`$app->run()`), making automated testing effortless:
```php
$app = new Wisp\App();
$app->get('/ping', function() {
    return Response::json(['message' => 'pong']);
});

// Execute in-memory without starting a web server or triggering exit/header:
$req = Request::create('GET', '/ping');
$res = $app->handle($req);

assert($res->status === 200);
assert($res->json['message'] === 'pong');
```

### 7. HTTP Request & Response Helpers
Wisp provides convenient developer helpers on both `Request` and `Response`:

#### Request Helpers:
```php
$app->post('/api/profile', function($req) {
    // 1. Unified inputs (checks JSON body, POST form-data, and query params)
    $username = $req->input('username', 'Anonymous');
    $all = $req->all();
    $data = $req->only(['username', 'email']);
    $safe = $req->except(['password']);

    // 2. Authentication token extraction
    $token = $req->bearerToken(); // extracts from 'Authorization: Bearer <token>'

    // 3. Client IP (handles Cloudflare CF-Connecting-IP, X-Forwarded-For, and REMOTE_ADDR)
    $ip = $req->ip();

    // 4. Cookies & file uploads
    $cookie = $req->cookie('session_id');
    $avatar = $req->file('avatar');

    return Response::json(['username' => $username, 'client_ip' => $ip]);
});
```

#### Response Helpers:
```php
// 1. Serve static file with automatic MIME-type detection
$app->get('/images/logo.png', function() {
    return Response::file(__DIR__ . '/../storage/logo.png');
});

// 2. Force download attachment
$app->get('/invoices/:id/download', function($req) {
    return Response::download(__DIR__ . '/../storage/invoice.pdf', 'my-invoice.pdf');
});

// 3. Cookies and fluent status/headers
$app->post('/login', function() {
    return Response::json(['success' => true])
        ->status(200)
        ->setCookie('auth_token', 'jwt_xyz', [
            'max_age' => 86400,
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Strict'
        ])
        ->withHeaders(['X-Logged-In' => 'true']);
});
```

### 8. Database Transactions & Helpers
Execute database operations safely using Wisp's built-in PDO transaction runner with automatic commit/rollback and nested savepoint support:
```php
// 1. Automatic Transaction Runner
DB::transaction(function(PDO $pdo) {
    DB::execute("UPDATE accounts SET balance = balance - 100 WHERE id = 1");
    DB::execute("UPDATE accounts SET balance = balance + 100 WHERE id = 2");
    // If any exception is thrown, changes are rolled back automatically!
});

// 2. Query convenience helpers
$user = DB::first("SELECT * FROM users WHERE email = :email", ['email' => $email]);
$totalUsers = DB::value("SELECT count(*) FROM users");
$newId = DB::lastInsertId();
```

### 9. Wisp CLI Tooling
Wisp includes a zero-dependency CLI tool accessible via `php wisp` (or `wisp` on Windows):

```bash
# List all registered HTTP routes in an ASCII table
php wisp route:list

# Start the local PHP development server
php wisp serve --host=127.0.0.1 --port=8000

# Scaffold classes with auto-wired boilerplate
php wisp make:service PaymentService
php wisp make:middleware CheckRoleMiddleware
php wisp make:route products

# Generate a secure 32-byte API secret key and write to .env
php wisp env:key
```

You can also register custom commands in your app or plugins via `$app->command()`:
```php
$app->command('backup:db', function($args) {
    echo "Backing up database...\n";
    return 0;
}, 'Backup database records');
```

---

## Plugins

Wisp's core stays intentionally minimal — optional functionality (JWT auth, a fluent
query builder, etc.) ships as separate, downloadable plugins instead of being built
into the framework. Nothing under `plugins/` is required for Wisp to run.

**How it works:** any folder dropped into `plugins/` whose entry file matches the
folder name (`plugins/<name>/<name>.php`), or any flat `plugins/*.php` file, is
automatically `require`'d at boot by `public/index.php`. A plugin wires itself up
using the extension points already built into core:

- `Request::macro(string $name, callable $fn)` — add methods to every `Request` (e.g. `$req->user()`)
- `Request::setAttribute()` / `getAttribute()` — per-request data bag for middleware to populate
- `DB::registerPlugin(string $className)` — swap in a query builder used by `DB::table()`
- `App::registerCallbackResolver(callable $resolver)` — support alternate route callback formats

No manifest or dependency system exists yet — installing a plugin is just copying its
`plugins/<name>/` folder into your project and setting any `.env` values it documents.

**Official plugins:**
- [wisp-jwt](https://github.com/tlek-tech/wisp-jwt) — HS256 JWT auth (`JwtMiddleware`, `\Wisp\Jwt`, `$req->user()`)
- [wisp-db-builder](https://github.com/tlek-tech/wisp-db-builder) — fluent query builder for `DB::table()`
- [wisp-migration](https://github.com/tlek-tech/wisp-migration) — database migrations and CLI runner (`wisp migrate`, `\Wisp\Migrator`)
- [wisp-mvc](https://github.com/tlek-tech/wisp-mvc) — MVC architecture with Controllers, Models (Active Record), and Blade-style Views

---

## About the Name

If you are curious about the name, **Wisp** stands for **W**orkers **i**n **S**uper-small **P**HP! 🌬️

It is designed to be as lightweight and fast as a whisper of wind, yet run a powerful asynchronous-style worker pipeline.

---

## License

This project is open-source and licensed under the [MIT License](LICENSE).
