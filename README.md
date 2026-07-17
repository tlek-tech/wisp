# Wisp 🌬️
> A Cloudflare Workers style micro-framework for PHP 7.4 to 8.x.

Wisp is a lightweight, ultra-fast PHP micro-framework designed for developers who love the serverless, callback-centric developer experience of Cloudflare Workers and Hono. 

It features an **onion-style middleware pipeline**, a **reflective auto-wiring Dependency Injection container**, and support for **clean URLs** right out of the box, with **zero external dependencies required** to start.

---

## Key Features

- ⚡ **Workers Style Syntax**: Route requests using `$app->get()`, `$app->post()`, and return standard `Response` objects.
- 🧅 **Onion Middleware**: Supports global, path-prefix (wildcard), inline, and route-specific middleware pipelines.
- 🧬 **Reflective Auto-Wiring**: Simply put your classes in the `services/` directory, and Wisp will automatically resolve constructor dependencies and instantiate them on demand.
- ⚙️ **Decoupled Configs**: Keep `public/index.php` pristine by separating global configurations into `config/`.
- 📁 **Clean URLs**: Front controller setup with rewrite rules for Apache/XAMPP (`.htaccess`) and built-in routing for local PHP dev server.
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
│   └── DB.php                  # Database wrapper around PDO (MySQL 5+)
│
├── middlewares/                # Custom route/global middleware classes
│   ├── AuthMiddleware.php      # Example: Bearer token validation middleware
│   └── RateLimitMiddleware.php # Example: Rate limit middleware class
│
├── services/                   # Custom service classes (Auto-wired)
│   └── TranslateService.php    # Example: Service class with automatic DB injection
│
└── routes/                     # Dynamic route files (Auto-loaded)
    ├── version.php             # Example: Version check endpoint route
    └── license.php             # Example: License and translation endpoint routes
```

---

## Getting Started

### 1. Requirements
- PHP 7.4 or higher (fully compatible with PHP 8.0, 8.1, 8.2, 8.3+)
- MySQL (if using database functionality)
- Composer (optional)

### 2. Setup
Clone the repository, create your `.env` configuration file from the template, and configure your database settings:
```bash
cp .env.example .env
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
Create custom services in the `services/` directory. For example, `services/TranslateService.php`:
```php
<?php

class TranslateService {
    private $db;

    // Wisp automatically resolves and injects the registered 'db' service (PDO instance)!
    public function __construct($db) {
        $this->db = $db;
    }

    public function translate(string $text): string {
        // Query the database via $this->db
        return "Translated: " . strtoupper($text);
    }
}
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

---

## About the Name

If you are curious about the name, **Wisp** stands for **W**orkers **i**n **S**uper-small **P**HP! 🌬️

It is designed to be as lightweight and fast as a whisper of wind, yet run a powerful asynchronous-style worker pipeline.

---

## License

This project is open-source and licensed under the [MIT License](LICENSE).
