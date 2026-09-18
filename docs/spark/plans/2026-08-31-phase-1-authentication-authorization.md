# Phase 1 Authentication and Authorization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add login, logout, authenticated session handling, role-aware authorization, and Admin-only user management on top of the Phase 0 native PHP bootstrap.

**Architecture:** Keep HTTP concerns in controllers, business/security rules in services and security helpers, and persistence in repository implementations behind interfaces. Session access is isolated behind `SessionManager` so `AuthService`, `AuthGuard`, and user-management authorization can be tested without PHP global session state.

**Tech Stack:** PHP 8.2+, Composer PSR-4, MySQL 8, PDO prepared statements, native PHP sessions, PHPUnit 10, PHPStan level 5, HTML/CSS, Vanilla JavaScript reserved for later slices.

## Global Constraints

- PHP 8.2+ Native OOP.
- MySQL 8 + PDO prepared statements.
- Controller -> Service -> Repository separation.
- Repository interface boundary with manual constructor injection.
- HTML + custom CSS + Vanilla JS + Fetch API.
- Docker Compose.
- PHPUnit unit + integration tests.
- PHPStan level 5+ preferred.
- No Laravel/CodeIgniter/Symfony/Slim/ORM/framework DI container.
- No React/Vue/Angular/jQuery/CSS framework/admin template.
- Never mutate stock outside stock service transaction + ledger.
- Never place authorization only in UI.
- Phase 1 must not implement product/master-data CRUD, Purchase Orders, Sales Orders, stock mutation, dashboard, reports, API, low-stock job, password reset, email verification, SSO, OAuth, MFA, Redis-backed sessions, or rate limiting.

---

## File Structure Map

- `database/schema-and-seed.sql`: extend Phase 0 schema with `users` table and safe demo users.
- `app/Entity/User.php`: immutable user data returned by repositories.
- `app/Repository/Contract/UserRepositoryInterface.php`: user lookup and persistence boundary.
- `app/Repository/InMemory/InMemoryUserRepository.php`: fake repository for unit tests.
- `app/Repository/MySql/MySqlUserRepository.php`: PDO prepared-statement implementation.
- `app/Support/DatabaseFactory.php`: creates PDO from `Config`.
- `app/Security/SessionManager.php`: testable session abstraction.
- `app/Security/NativeSessionManager.php`: PHP session-backed implementation.
- `app/Security/AuthContext.php`: authenticated identity value.
- `app/Security/Authorization.php`: role/ownership permission checks.
- `app/Security/AuthGuard.php`: route-level authentication and authorization helper.
- `app/Service/AuthService.php`: login/logout behavior.
- `app/Service/UserService.php`: Admin-only user management behavior.
- `app/Controller/AuthController.php`: login/logout HTTP actions.
- `app/Controller/UserController.php`: Admin-only user management HTTP actions.
- `views/auth/login.php`: login form.
- `views/users/index.php`: user list.
- `views/users/create.php`: create-user form.
- `views/users/edit.php`: edit-user form.
- `views/errors/403.php`: safe forbidden page.
- `public/index.php`: wire repositories/services/controllers and Phase 1 routes manually.
- `tests/Unit/AuthServiceTest.php`: auth service behavior.
- `tests/Unit/AuthorizationTest.php`: permission behavior.
- `tests/Unit/UserServiceTest.php`: Admin-only user management behavior.
- `tests/Integration/UserRepositoryIntegrationTest.php`: MySQL user repository/schema checks.
- `docs/testing/test-scenarios.md`: append Phase 1 test scenarios/results.
- `docs/quality/tech-debt.md`: update known environment/tooling risks if still present.
- `ai-usage-log.md`: record AI-assisted Phase 1 plan creation.

---

### Task 1: User Schema and Entity

**Files:**
- Modify: `database/schema-and-seed.sql`
- Create: `app/Entity/User.php`
- Test: `tests/Unit/UserEntityTest.php`

**Interfaces:**
- Consumes: Phase 0 Composer autoload namespace `App\\`.
- Produces: `App\Entity\User::__construct(int $id, string $name, string $email, string $passwordHash, string $role, bool $isActive)`, plus getters `id()`, `name()`, `email()`, `passwordHash()`, `role()`, `isActive()`.

- [ ] **Step 1: Write the failing entity test**

Create `tests/Unit/UserEntityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserEntityTest extends TestCase
{
    public function testUserExposesIdentityRoleAndActiveState(): void
    {
        $user = new User(1, 'Admin User', 'admin@example.test', 'hash', 'Admin', true);

        self::assertSame(1, $user->id());
        self::assertSame('Admin User', $user->name());
        self::assertSame('admin@example.test', $user->email());
        self::assertSame('hash', $user->passwordHash());
        self::assertSame('Admin', $user->role());
        self::assertTrue($user->isActive());
    }
}
```

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
composer test -- --filter UserEntityTest
```

Expected: FAIL because `App\Entity\User` does not exist.

- [ ] **Step 3: Create `app/Entity/User.php`**

Write:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

final class User
{
    public const ROLE_ADMIN = 'Admin';
    public const ROLE_SALES = 'Sales';
    public const ROLE_WAREHOUSE_STAFF = 'WarehouseStaff';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_SALES,
        self::ROLE_WAREHOUSE_STAFF,
    ];

    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $role,
        private readonly bool $isActive,
    ) {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unsupported role: {$role}");
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
```

- [ ] **Step 4: Extend `database/schema-and-seed.sql`**

Append after the Phase 0 `schema_versions` insert:

```sql

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Sales', 'WarehouseStaff') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB;

INSERT INTO users (name, email, password_hash, role, is_active)
VALUES
    ('Admin Demo', 'admin@example.test', '$2y$10$zY9rZE5ZgLlCpL9MjV4jE.YrrI96W6iwK9iI8z1M3Gz6x2Nw0C8Iq', 'Admin', 1),
    ('Sales Demo One', 'sales1@example.test', '$2y$10$zY9rZE5ZgLlCpL9MjV4jE.YrrI96W6iwK9iI8z1M3Gz6x2Nw0C8Iq', 'Sales', 1),
    ('Sales Demo Two', 'sales2@example.test', '$2y$10$zY9rZE5ZgLlCpL9MjV4jE.YrrI96W6iwK9iI8z1M3Gz6x2Nw0C8Iq', 'Sales', 1),
    ('Warehouse Demo One', 'warehouse1@example.test', '$2y$10$zY9rZE5ZgLlCpL9MjV4jE.YrrI96W6iwK9iI8z1M3Gz6x2Nw0C8Iq', 'WarehouseStaff', 1),
    ('Warehouse Demo Two', 'warehouse2@example.test', '$2y$10$zY9rZE5ZgLlCpL9MjV4jE.YrrI96W6iwK9iI8z1M3Gz6x2Nw0C8Iq', 'WarehouseStaff', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    role = VALUES(role),
    is_active = VALUES(is_active);
```

The demo hash is a bcrypt hash for the fake local password `password`.

- [ ] **Step 5: Run the focused test and full unit smoke**

Run:

```bash
composer test -- --filter UserEntityTest
composer test:unit
```

Expected: `UserEntityTest` passes, and existing unit tests still pass.

- [ ] **Step 6: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add app/Entity/User.php database/schema-and-seed.sql tests/Unit/UserEntityTest.php
git commit -m "feat: add user schema and entity"
```

Expected if Git is not initialized: record `Git repository is not initialized` in the task report.

---

### Task 2: User Repository Boundary

**Files:**
- Create: `app/Repository/Contract/UserRepositoryInterface.php`
- Create: `app/Repository/InMemory/InMemoryUserRepository.php`
- Create: `app/Repository/MySql/MySqlUserRepository.php`
- Create: `app/Support/DatabaseFactory.php`
- Test: `tests/Unit/UserRepositoryTest.php`

**Interfaces:**
- Consumes: `App\Entity\User`.
- Produces: `UserRepositoryInterface::findByEmail(string $email): ?User`, `findById(int $id): ?User`, `all(): array`, `create(...)`, `update(...)`, `setActive(...)`.

- [ ] **Step 1: Write the failing fake repository test**

Create `tests/Unit/UserRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Repository\InMemory\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
    public function testFindsUserByEmailCaseInsensitively(): void
    {
        $repository = new InMemoryUserRepository([
            new User(1, 'Admin User', 'admin@example.test', 'hash', User::ROLE_ADMIN, true),
        ]);

        self::assertSame(1, $repository->findByEmail('ADMIN@example.test')?->id());
    }

    public function testCreatesUserWithNextId(): void
    {
        $repository = new InMemoryUserRepository([]);

        $user = $repository->create('Sales User', 'sales@example.test', 'hash', User::ROLE_SALES, true);

        self::assertSame(1, $user->id());
        self::assertSame('sales@example.test', $repository->findById(1)?->email());
    }
}
```

- [ ] **Step 2: Run the focused test and confirm it fails**

Run:

```bash
composer test -- --filter UserRepositoryTest
```

Expected: FAIL because repository classes do not exist.

- [ ] **Step 3: Create `UserRepositoryInterface`**

Write `app/Repository/Contract/UserRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    /**
     * @return list<User>
     */
    public function all(): array;

    public function create(string $name, string $email, string $passwordHash, string $role, bool $isActive): User;

    public function update(int $id, string $name, string $email, string $role, bool $isActive): User;

    public function setActive(int $id, bool $isActive): User;
}
```

- [ ] **Step 4: Create `InMemoryUserRepository`**

Write `app/Repository/InMemory/InMemoryUserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $users = [];

    private int $nextId = 1;

    /**
     * @param list<User> $users
     */
    public function __construct(array $users)
    {
        foreach ($users as $user) {
            $this->users[$user->id()] = $user;
            $this->nextId = max($this->nextId, $user->id() + 1);
        }
    }

    public function findByEmail(string $email): ?User
    {
        $target = strtolower($email);

        foreach ($this->users as $user) {
            if (strtolower($user->email()) === $target) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function create(string $name, string $email, string $passwordHash, string $role, bool $isActive): User
    {
        if ($this->findByEmail($email) !== null) {
            throw new \RuntimeException('Email already exists.');
        }

        $user = new User($this->nextId++, $name, strtolower($email), $passwordHash, $role, $isActive);
        $this->users[$user->id()] = $user;

        return $user;
    }

    public function update(int $id, string $name, string $email, string $role, bool $isActive): User
    {
        if (!isset($this->users[$id])) {
            throw new \RuntimeException('User not found.');
        }

        $existing = $this->findByEmail($email);
        if ($existing !== null && $existing->id() !== $id) {
            throw new \RuntimeException('Email already exists.');
        }

        $current = $this->users[$id];
        $user = new User($id, $name, strtolower($email), $current->passwordHash(), $role, $isActive);
        $this->users[$id] = $user;

        return $user;
    }

    public function setActive(int $id, bool $isActive): User
    {
        $user = $this->users[$id] ?? null;
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $updated = new User($id, $user->name(), $user->email(), $user->passwordHash(), $user->role(), $isActive);
        $this->users[$id] = $updated;

        return $updated;
    }
}
```

- [ ] **Step 5: Create `MySqlUserRepository`**

Write `app/Repository/MySql/MySqlUserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository\MySql;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use PDO;

final class MySqlUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => strtolower($email)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($row) : null;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($row) : null;
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM users ORDER BY id ASC');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): User => $this->map($row), $rows);
    }

    public function create(string $name, string $email, string $passwordHash, string $role, bool $isActive): User
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active) VALUES (:name, :email, :password_hash, :role, :is_active)'
        );
        $statement->execute([
            'name' => $name,
            'email' => strtolower($email),
            'password_hash' => $passwordHash,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId())
            ?? throw new \RuntimeException('Created user could not be loaded.');
    }

    public function update(int $id, string $name, string $email, string $role, bool $isActive): User
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET name = :name, email = :email, role = :role, is_active = :is_active WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'email' => strtolower($email),
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $this->findById($id) ?? throw new \RuntimeException('User not found.');
    }

    public function setActive(int $id, bool $isActive): User
    {
        $statement = $this->pdo->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $this->findById($id) ?? throw new \RuntimeException('User not found.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['role'],
            (bool) $row['is_active'],
        );
    }
}
```

- [ ] **Step 6: Create `DatabaseFactory`**

Write `app/Support/DatabaseFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class DatabaseFactory
{
    public static function create(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->string('DB_HOST'),
            $config->int('DB_PORT'),
            $config->string('DB_DATABASE'),
        );

        return new PDO($dsn, $config->string('DB_USERNAME'), $config->string('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
```

- [ ] **Step 7: Run focused and static checks**

Run:

```bash
composer test -- --filter UserRepositoryTest
composer analyse
```

Expected: repository unit test passes and PHPStan reports no errors.

- [ ] **Step 8: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add app/Repository app/Support/DatabaseFactory.php tests/Unit/UserRepositoryTest.php
git commit -m "feat: add user repository boundary"
```

---

### Task 3: Session and Auth Context

**Files:**
- Create: `app/Security/AuthContext.php`
- Create: `app/Security/SessionManager.php`
- Create: `app/Security/NativeSessionManager.php`
- Test: `tests/Unit/SessionManagerTest.php`

**Interfaces:**
- Consumes: `App\Entity\User`.
- Produces: `SessionManager::regenerate(): void`, `setUser(User $user): void`, `user(): ?AuthContext`, `clear(): void`; `AuthContext` getters `id()`, `name()`, `email()`, `role()`.

- [ ] **Step 1: Write failing session test**

Create `tests/Unit/SessionManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Security\NativeSessionManager;
use PHPUnit\Framework\TestCase;

final class SessionManagerTest extends TestCase
{
    public function testStoresAndClearsAuthenticatedUserInArrayBackedSession(): void
    {
        $storage = [];
        $session = new NativeSessionManager($storage, false);
        $user = new User(7, 'Sales User', 'sales@example.test', 'hash', User::ROLE_SALES, true);

        $session->setUser($user);

        self::assertSame(7, $session->user()?->id());
        self::assertSame(User::ROLE_SALES, $session->user()?->role());

        $session->clear();

        self::assertNull($session->user());
    }
}
```

- [ ] **Step 2: Run focused test and confirm it fails**

Run:

```bash
composer test -- --filter SessionManagerTest
```

Expected: FAIL because security session classes do not exist.

- [ ] **Step 3: Create `AuthContext`**

Write `app/Security/AuthContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

final class AuthContext
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $email,
        private readonly string $role,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): string
    {
        return $this->role;
    }
}
```

- [ ] **Step 4: Create `SessionManager`**

Write `app/Security/SessionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

interface SessionManager
{
    public function regenerate(): void;

    public function setUser(User $user): void;

    public function user(): ?AuthContext;

    public function clear(): void;
}
```

- [ ] **Step 5: Create `NativeSessionManager`**

Write `app/Security/NativeSessionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

final class NativeSessionManager implements SessionManager
{
    /**
     * @param array<string, mixed> $storage
     */
    public function __construct(
        private array &$storage,
        private readonly bool $manageNativeSession = true,
    ) {
        if ($this->manageNativeSession && session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function regenerate(): void
    {
        if ($this->manageNativeSession && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function setUser(User $user): void
    {
        $this->storage['auth_user'] = [
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
            'role' => $user->role(),
        ];
    }

    public function user(): ?AuthContext
    {
        $user = $this->storage['auth_user'] ?? null;

        if (!is_array($user)) {
            return null;
        }

        return new AuthContext(
            (int) $user['id'],
            (string) $user['name'],
            (string) $user['email'],
            (string) $user['role'],
        );
    }

    public function clear(): void
    {
        unset($this->storage['auth_user']);

        if ($this->manageNativeSession && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
```

- [ ] **Step 6: Run focused test**

Run:

```bash
composer test -- --filter SessionManagerTest
```

Expected: PASS.

- [ ] **Step 7: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add app/Security tests/Unit/SessionManagerTest.php
git commit -m "feat: add session auth context"
```

---

### Task 4: Auth Service

**Files:**
- Create: `app/Service/AuthService.php`
- Test: `tests/Unit/AuthServiceTest.php`

**Interfaces:**
- Consumes: `UserRepositoryInterface`, `SessionManager`.
- Produces: `AuthService::login(string $email, string $password): AuthContext`, `AuthService::logout(): void`.

- [ ] **Step 1: Write failing auth service tests**

Create `tests/Unit/AuthServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\SessionManager;
use App\Service\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    public function testAdminCanAuthenticateWithValidActiveCredentials(): void
    {
        $session = new SpySessionManager();
        $repository = new InMemoryUserRepository([
            new User(1, 'Admin User', 'admin@example.test', password_hash('secret', PASSWORD_DEFAULT), User::ROLE_ADMIN, true),
        ]);
        $service = new AuthService($repository, $session);

        $context = $service->login('admin@example.test', 'secret');

        self::assertSame(1, $context->id());
        self::assertTrue($session->regenerated);
    }

    public function testInvalidCredentialsUseGenericMessage(): void
    {
        $service = new AuthService(new InMemoryUserRepository([]), new SpySessionManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid email or password.');

        $service->login('missing@example.test', 'wrong');
    }

    public function testInactiveUserCannotAuthenticate(): void
    {
        $repository = new InMemoryUserRepository([
            new User(2, 'Inactive User', 'inactive@example.test', password_hash('secret', PASSWORD_DEFAULT), User::ROLE_SALES, false),
        ]);
        $service = new AuthService($repository, new SpySessionManager());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid email or password.');

        $service->login('inactive@example.test', 'secret');
    }

    public function testLogoutClearsSession(): void
    {
        $session = new SpySessionManager();
        $service = new AuthService(new InMemoryUserRepository([]), $session);

        $service->logout();

        self::assertTrue($session->cleared);
    }
}

final class SpySessionManager implements SessionManager
{
    public bool $regenerated = false;
    public bool $cleared = false;
    private ?AuthContext $context = null;

    public function regenerate(): void
    {
        $this->regenerated = true;
    }

    public function setUser(User $user): void
    {
        $this->context = new AuthContext($user->id(), $user->name(), $user->email(), $user->role());
    }

    public function user(): ?AuthContext
    {
        return $this->context;
    }

    public function clear(): void
    {
        $this->cleared = true;
        $this->context = null;
    }
}
```

- [ ] **Step 2: Run focused test and confirm it fails**

Run:

```bash
composer test -- --filter AuthServiceTest
```

Expected: FAIL because `AuthService` does not exist.

- [ ] **Step 3: Create `AuthService`**

Write `app/Service/AuthService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthContext;
use App\Security\SessionManager;

final class AuthService
{
    private const INVALID_LOGIN_MESSAGE = 'Invalid email or password.';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly SessionManager $session,
    ) {
    }

    public function login(string $email, string $password): AuthContext
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !$user->isActive() || !password_verify($password, $user->passwordHash())) {
            throw new \RuntimeException(self::INVALID_LOGIN_MESSAGE);
        }

        $this->session->regenerate();
        $this->session->setUser($user);

        return $this->session->user() ?? throw new \RuntimeException('Authenticated session could not be established.');
    }

    public function logout(): void
    {
        $this->session->clear();
    }
}
```

- [ ] **Step 4: Run focused auth tests**

Run:

```bash
composer test -- --filter AuthServiceTest
```

Expected: PASS with 4 tests.

- [ ] **Step 5: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add app/Service/AuthService.php tests/Unit/AuthServiceTest.php
git commit -m "feat: add authentication service"
```

---

### Task 5: Authorization and User Service

**Files:**
- Create: `app/Security/Authorization.php`
- Create: `app/Security/AuthGuard.php`
- Create: `app/Service/UserService.php`
- Test: `tests/Unit/AuthorizationTest.php`
- Test: `tests/Unit/UserServiceTest.php`

**Interfaces:**
- Consumes: `AuthContext`, `UserRepositoryInterface`.
- Produces: `Authorization::assertCanManageUsers(AuthContext $actor): void`; `AuthGuard::requireUser(): AuthContext`; `AuthGuard::requireAdmin(): AuthContext`; `UserService::create(...)`, `update(...)`, `setActive(...)`, `all(...)`.

- [ ] **Step 1: Write failing authorization tests**

Create `tests/Unit/AuthorizationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Security\AuthContext;
use App\Security\Authorization;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    public function testAdminCanManageUsers(): void
    {
        $authorization = new Authorization();

        $authorization->assertCanManageUsers(new AuthContext(1, 'Admin', 'admin@example.test', User::ROLE_ADMIN));

        self::assertTrue(true);
    }

    public function testSalesCannotManageUsers(): void
    {
        $authorization = new Authorization();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Forbidden.');

        $authorization->assertCanManageUsers(new AuthContext(2, 'Sales', 'sales@example.test', User::ROLE_SALES));
    }
}
```

- [ ] **Step 2: Write failing user service tests**

Create `tests/Unit/UserServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\User;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Security\AuthContext;
use App\Security\Authorization;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testAdminCreatesUserWithHashedPassword(): void
    {
        $service = new UserService(new InMemoryUserRepository([]), new Authorization());
        $actor = new AuthContext(1, 'Admin', 'admin@example.test', User::ROLE_ADMIN);

        $user = $service->create($actor, 'Sales User', 'sales@example.test', 'secret123', User::ROLE_SALES, true);

        self::assertSame(User::ROLE_SALES, $user->role());
        self::assertNotSame('secret123', $user->passwordHash());
        self::assertTrue(password_verify('secret123', $user->passwordHash()));
    }

    public function testSalesCannotCreateUser(): void
    {
        $service = new UserService(new InMemoryUserRepository([]), new Authorization());
        $actor = new AuthContext(2, 'Sales', 'sales@example.test', User::ROLE_SALES);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Forbidden.');

        $service->create($actor, 'User', 'user@example.test', 'secret123', User::ROLE_SALES, true);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $repository = new InMemoryUserRepository([
            new User(2, 'Sales', 'sales@example.test', 'hash', User::ROLE_SALES, true),
        ]);
        $service = new UserService($repository, new Authorization());
        $actor = new AuthContext(1, 'Admin', 'admin@example.test', User::ROLE_ADMIN);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email already exists.');

        $service->create($actor, 'Other', 'sales@example.test', 'secret123', User::ROLE_SALES, true);
    }
}
```

- [ ] **Step 3: Run focused tests and confirm they fail**

Run:

```bash
composer test -- --filter 'AuthorizationTest|UserServiceTest'
```

Expected: FAIL because `Authorization` and `UserService` do not exist.

- [ ] **Step 4: Create `Authorization`**

Write `app/Security/Authorization.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

final class Authorization
{
    public function assertCanManageUsers(AuthContext $actor): void
    {
        if ($actor->role() !== User::ROLE_ADMIN) {
            throw new \RuntimeException('Forbidden.');
        }
    }
}
```

- [ ] **Step 5: Create `AuthGuard`**

Write `app/Security/AuthGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

final class AuthGuard
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly Authorization $authorization,
    ) {
    }

    public function requireUser(): AuthContext
    {
        return $this->session->user() ?? throw new \RuntimeException('Unauthenticated.');
    }

    public function requireAdmin(): AuthContext
    {
        $actor = $this->requireUser();
        $this->authorization->assertCanManageUsers($actor);

        return $actor;
    }
}
```

- [ ] **Step 6: Create `UserService`**

Write `app/Service/UserService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contract\UserRepositoryInterface;
use App\Security\AuthContext;
use App\Security\Authorization;

final class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly Authorization $authorization,
    ) {
    }

    /**
     * @return list<User>
     */
    public function all(AuthContext $actor): array
    {
        $this->authorization->assertCanManageUsers($actor);

        return $this->users->all();
    }

    public function create(AuthContext $actor, string $name, string $email, string $password, string $role, bool $isActive): User
    {
        $this->authorization->assertCanManageUsers($actor);
        $this->validateUserInput($name, $email, $role);

        if ($password === '') {
            throw new \InvalidArgumentException('Password is required.');
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Email already exists.');
        }

        return $this->users->create($name, strtolower($email), password_hash($password, PASSWORD_DEFAULT), $role, $isActive);
    }

    public function update(AuthContext $actor, int $id, string $name, string $email, string $role, bool $isActive): User
    {
        $this->authorization->assertCanManageUsers($actor);
        $this->validateUserInput($name, $email, $role);

        return $this->users->update($id, $name, strtolower($email), $role, $isActive);
    }

    public function setActive(AuthContext $actor, int $id, bool $isActive): User
    {
        $this->authorization->assertCanManageUsers($actor);

        return $this->users->setActive($id, $isActive);
    }

    private function validateUserInput(string $name, string $email, string $role): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Name is required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Valid email is required.');
        }

        if (!in_array($role, User::ROLES, true)) {
            throw new \InvalidArgumentException('Role is invalid.');
        }
    }
}
```

- [ ] **Step 7: Run focused tests**

Run:

```bash
composer test -- --filter 'AuthorizationTest|UserServiceTest'
```

Expected: PASS.

- [ ] **Step 8: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add app/Security/Authorization.php app/Security/AuthGuard.php app/Service/UserService.php tests/Unit/AuthorizationTest.php tests/Unit/UserServiceTest.php
git commit -m "feat: add user authorization service"
```

---

### Task 6: Auth and User Controllers

**Files:**
- Create: `app/Controller/AuthController.php`
- Create: `app/Controller/UserController.php`
- Create: `views/auth/login.php`
- Create: `views/users/index.php`
- Create: `views/users/create.php`
- Create: `views/users/edit.php`
- Create: `views/errors/403.php`
- Modify: `public/index.php`

**Interfaces:**
- Consumes: `AuthService`, `UserService`, `AuthGuard`, `Request`, `Response`.
- Produces: browser routes `GET /login`, `POST /login`, `POST /logout`, `GET /users`, `GET /users/create`, `POST /users`, `GET /users/{id}/edit`, `POST /users/{id}`, `POST /users/{id}/status`.

- [ ] **Step 1: Create `AuthController`**

Write `app/Controller/AuthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function showLogin(Request $request): Response
    {
        return Response::html($this->renderLogin());
    }

    public function login(Request $request): Response
    {
        try {
            $this->auth->login((string) ($request->post()['email'] ?? ''), (string) ($request->post()['password'] ?? ''));
        } catch (\RuntimeException) {
            return Response::html($this->renderLogin('Invalid email or password.'), 422);
        }

        return new Response('', 302, ['Location' => '/']);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return new Response('', 302, ['Location' => '/login']);
    }

    private function renderLogin(string $error = ''): string
    {
        ob_start();
        $safeError = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
        require dirname(__DIR__, 2) . '/views/auth/login.php';

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 2: Create `UserController`**

Write `app/Controller/UserController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\UserService;

final class UserController
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuthGuard $guard,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireAdmin();
        $users = $this->users->all($actor);

        ob_start();
        require dirname(__DIR__, 2) . '/views/users/index.php';

        return Response::html((string) ob_get_clean());
    }

    public function create(Request $request): Response
    {
        $this->guard->requireAdmin();
        $roles = User::ROLES;
        $error = '';

        ob_start();
        require dirname(__DIR__, 2) . '/views/users/create.php';

        return Response::html((string) ob_get_clean());
    }

    public function store(Request $request): Response
    {
        $actor = $this->guard->requireAdmin();
        $data = $request->post();

        try {
            $this->users->create(
                $actor,
                (string) ($data['name'] ?? ''),
                (string) ($data['email'] ?? ''),
                (string) ($data['password'] ?? ''),
                (string) ($data['role'] ?? ''),
                isset($data['is_active'])
            );
        } catch (\InvalidArgumentException $exception) {
            return Response::html(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), 422);
        }

        return new Response('', 302, ['Location' => '/users']);
    }
}
```

- [ ] **Step 3: Create login and user views**

Write `views/auth/login.php`:

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <main class="page">
        <h1>Login</h1>
        <?php if ($safeError !== ''): ?>
            <p><?= $safeError ?></p>
        <?php endif; ?>
        <form method="post" action="/login">
            <label>Email <input type="email" name="email" required></label>
            <label>Password <input type="password" name="password" required></label>
            <button type="submit">Login</button>
        </form>
    </main>
</body>
</html>
```

Write `views/users/index.php`:

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <main class="page">
        <h1>Users</h1>
        <p><a href="/users/create">Create user</a></p>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($user->email(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($user->role(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $user->isActive() ? 'Active' : 'Inactive' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
```

Write `views/users/create.php`:

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create User</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <main class="page">
        <h1>Create User</h1>
        <form method="post" action="/users">
            <label>Name <input type="text" name="name" required></label>
            <label>Email <input type="email" name="email" required></label>
            <label>Password <input type="password" name="password" required></label>
            <label>Role
                <select name="role" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
            <button type="submit">Create</button>
        </form>
    </main>
</body>
</html>
```

Write `views/users/edit.php`:

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit User</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <main class="page">
        <h1>Edit User</h1>
        <p>Edit route wiring may be completed in the same task as update/status actions.</p>
    </main>
</body>
</html>
```

Write `views/errors/403.php`:

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forbidden</title>
</head>
<body>
    <h1>Forbidden</h1>
    <p>You are not allowed to access this resource.</p>
</body>
</html>
```

- [ ] **Step 4: Wire Phase 1 routes in `public/index.php`**

Replace the composition section before the `try` block with:

```php
$pdo = App\Support\DatabaseFactory::create($config);
$userRepository = new App\Repository\MySql\MySqlUserRepository($pdo);
$session = new App\Security\NativeSessionManager($_SESSION);
$authorization = new App\Security\Authorization();
$authGuard = new App\Security\AuthGuard($session, $authorization);
$authService = new App\Service\AuthService($userRepository, $session);
$userService = new App\Service\UserService($userRepository, $authorization);

$homeController = new HomeController();
$authController = new App\Controller\AuthController($authService);
$userController = new App\Controller\UserController($userService, $authGuard);

$router->get('/', [$homeController, 'index']);
$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout']);
$router->get('/users', [$userController, 'index']);
$router->get('/users/create', [$userController, 'create']);
$router->post('/users', [$userController, 'store']);
```

If unauthenticated/forbidden exceptions are currently plain `RuntimeException`, catch them in `public/index.php` before the generic handler and map messages:

```php
} catch (RuntimeException $exception) {
    $status = $exception->getMessage() === 'Unauthenticated.' ? 401 : 403;
    $safeMessage = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    $response = Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Error</title><body><h1>Error</h1><p>' . $safeMessage . '</p></body></html>', $status);
}
```

- [ ] **Step 5: Run full tests and manual route checks**

Run:

```bash
composer test
composer analyse
docker compose up --build
```

Expected: tests and PHPStan pass; Docker app and database start. In another terminal:

```bash
curl -i http://localhost:8080/login
curl -i http://localhost:8080/users
```

Expected: `/login` returns HTTP 200; `/users` returns safe 401 or 403 when not authenticated.

- [ ] **Step 6: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add app/Controller public/index.php views
git commit -m "feat: add auth and user controllers"
```

---

### Task 7: Integration Tests and Evidence

**Files:**
- Create: `tests/Integration/UserRepositoryIntegrationTest.php`
- Modify: `docs/testing/test-scenarios.md`
- Modify: `docs/quality/phpstan-report.txt`
- Modify: `docs/quality/tech-debt.md`
- Modify: `ai-usage-log.md`

**Interfaces:**
- Consumes: MySQL Docker service, `MySqlUserRepository`, and `database/schema-and-seed.sql`.
- Produces: database-backed evidence for unique email, seeded demo users, and login lookup.

- [ ] **Step 1: Create integration test**

Write `tests/Integration/UserRepositoryIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\MySql\MySqlUserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserRepositoryIntegrationTest extends TestCase
{
    public function testFindsSeededAdminByEmail(): void
    {
        $pdo = new PDO(
            'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE') . ';charset=utf8mb4',
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $repository = new MySqlUserRepository($pdo);

        $admin = $repository->findByEmail('admin@example.test');

        self::assertNotNull($admin);
        self::assertSame('Admin', $admin->role());
        self::assertTrue($admin->isActive());
    }
}
```

- [ ] **Step 2: Run database integration check**

Run:

```bash
docker compose up -d db
docker compose run --rm app composer test:integration
```

Expected: integration suite passes and finds seeded Admin user.

- [ ] **Step 3: Run final Phase 1 verification**

Run:

```bash
composer test -- --filter Auth
composer test -- --filter User
composer test
composer analyse
```

Expected: all commands exit with status 0.

- [ ] **Step 4: Refresh PHPStan report**

Run:

```bash
composer analyse > docs/quality/phpstan-report.txt
```

Expected: report records a successful PHPStan run.

- [ ] **Step 5: Update `docs/testing/test-scenarios.md`**

Append:

```markdown

## Phase 1

| Scenario | Command | Expected Result |
|---|---|---|
| Auth service behavior | `composer test -- --filter AuthServiceTest` | Active valid credentials authenticate; invalid and inactive users fail generically; logout clears session. |
| Authorization behavior | `composer test -- --filter AuthorizationTest` | Admin can manage users; Sales cannot. |
| User service behavior | `composer test -- --filter UserServiceTest` | Admin creates users with hashed passwords; Sales is blocked; duplicate email fails. |
| User repository integration | `docker compose run --rm app composer test:integration` | Seeded Admin user can be loaded from MySQL by email. |
```

- [ ] **Step 6: Update `ai-usage-log.md`**

Append:

```markdown
| 2026-08-31 | Codex | Planned Phase 1 Authentication and Authorization implementation from the approved spec. | Developer requested `/rudis.plan`. | Plan self-review checked spec coverage, unresolved markers, and type consistency. |
```

- [ ] **Step 7: Record remaining tooling limitations only if still true**

If host PHP/Composer or Docker Desktop still blocks verification, keep `docs/quality/tech-debt.md` entries current and record exact failed commands in `docs/testing/test-scenarios.md`. If the environment is repaired, update those entries to mark the limitation resolved or superseded with command evidence.

- [ ] **Step 8: Commit checkpoint when Git exists**

Run:

```bash
git status --short
```

Expected if Git is initialized: changed files are listed. Commit with:

```bash
git add tests/Integration/UserRepositoryIntegrationTest.php docs/testing/test-scenarios.md docs/quality/phpstan-report.txt docs/quality/tech-debt.md ai-usage-log.md
git commit -m "test: add phase 1 auth evidence"
```

---

## Self-Review

Spec coverage:

- User schema and demo accounts: Task 1.
- User repository interface, MySQL repository, fake repository: Task 2.
- Password hashing and verification: Tasks 1, 4, 5.
- Session regeneration and logout clearing: Tasks 3, 4, 6.
- AuthContext/AuthGuard/Authorization: Tasks 3 and 5.
- Admin-only user management: Tasks 5 and 6.
- Auth and user unit/integration tests: Tasks 1-7.
- Server-side authorization: Tasks 5 and 6.
- Safe generic login failure and 401/403 behavior: Tasks 4 and 6.

Type consistency:

- `User` getters used by repositories/services/tests all match Task 1.
- `UserRepositoryInterface` methods used by `AuthService` and `UserService` match Task 2.
- `SessionManager` methods used by `AuthService` and `AuthGuard` match Task 3.
- Role values use exact `Admin`, `Sales`, `WarehouseStaff` values.

Scope control:

- No product/master-data CRUD beyond users.
- No PO/SO, stock mutation, dashboard, reports, API, low-stock job, optional enterprise scope, frameworks, ORM, DI container, frontend framework, CSS framework, or admin template.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-1-authentication-authorization.md`. Two execution options:

1. Subagent-Driven (recommended) - dispatch a fresh subagent per task, review between tasks, fast iteration.

2. Inline Execution - execute tasks in this session using executing-plans, batch execution with checkpoints.

Choose the execution approach before implementation starts.
