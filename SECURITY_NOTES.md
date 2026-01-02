# Security Notes

## Purpose
This repository is prepared for **technical review and interview showcase**.
Sensitive credentials and user-private artifacts are intentionally excluded.
Security controls described below reflect the system’s backend implementation
approach and design decisions.

---

## 1. Authentication & Session Management

### Session Initialization & Access Control
- User session initialization is centralized and enforced at the page entry level.
- Protected user-facing pages include a shared header file that:
  - loads system configuration
  - initializes the PHP session if not already started
  - retrieves authenticated user context from session storage

Example implementation:

```php
require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$_user = $_SESSION['user'] ?? null;
```

- Subsequent authorization checks are applied based on the session user context.

### Password Handling
- Passwords are never stored in plain text.
- Password hashing is applied before persistence.
- Password verification is performed using server-side validation logic.

---

## 2. Authorization & Role-Based Access Control (RBAC)
- The system enforces separation of permissions between **Admin** and **User** roles.
- Admin-only modules are placed under `admin/` and require authorization checks.
- Access control is validated server-side to prevent URL tampering or direct access
  to restricted pages.

---

## 3. Input Validation & Server-Side Enforcement
- User inputs are validated on the server side to prevent malformed requests.
- Mandatory fields, expected data types, and boundary checks are enforced.
- Validation is applied before database actions to reduce injection and logic abuse risks.

---

## 4. Database Security Practices

### Prepared Statements (PDO)
- Database access is performed using PDO.
- Parameter binding and prepared statements are used to reduce SQL injection risk.

### Error Handling
- Detailed database connection errors are not exposed to end users.
- Generic error messages are used to avoid information leakage.

---

## 5. Sensitive Data Handling (Repository Hygiene)

### Excluded from Public Repository
The following are intentionally excluded from this public repository:
- Database credentials and secrets
- API keys / tokens
- `.env` files and local overrides
- User-submitted sensitive documents (e.g., refund proof)

### Demo Media Policy
- `uploads/` contains **limited demo media only** for UI showcase.
- Sensitive content folders are removed.
- A `README.md` is used within media folders to clarify intended usage.

---

## 6. File Upload & Content Safety (If Applicable)
- Uploaded files are expected to be validated by:
  - file type / extension checks
  - size limits
  - controlled storage paths
- User-uploaded private artifacts are not included in this repository.

---

## 7. AI/ML Module Security Considerations
For the Visual Search module (Python-based):
- AI processing is treated as a separate component to reduce coupling.
- Only required scripts and demo assets are included for showcase.
- Large datasets, model weights, and generated indexes may be excluded to prevent
  oversized repositories and accidental data exposure.

---

## 8. Operational Notes (Interview Context)
This repository represents a **functional prototype** and a **showcase build**.
In a production setting, additional controls would typically be implemented, such as:
- Environment-based secret management
- Centralized logging with access control
- Rate limiting for sensitive endpoints
- Stronger file upload scanning and content policies

---

## Summary
Security in this project is implemented through:
- authentication and session checks
- role-based access control
- server-side validation
- PDO prepared statements
- repository-level protection of secrets and private artifacts

These controls collectively reduce common web security risks and support safe
system operation in an e-commerce context.
