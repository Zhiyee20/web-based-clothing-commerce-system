# Security Notes

## Purpose
This repository documents the security-related design considerations and implementation practices applied within the system.

Sensitive credentials and user-private files are intentionally excluded.
The security controls described below reflect the backend implementation approach and system design decisions.

---

## 1. Authentication & Session Management

### Session Initialization & Access Control
- User session initialization is centralized and enforced at the page entry level.
- Protected user-side pages include a shared header file that:
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

## 2. Authorization & Role-Based Access Control
- The system enforces separation of permissions between **Admin** and **User** roles.
- Admin-only modules are placed under `admin/` and require authorization checks.
- Access control is validated server-side to prevent URL tampering or direct access to restricted pages.

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

## 5. Sensitive Data Handling (Repository Management)

### Excluded from Public Repository
The following are intentionally excluded from this public repository:
- Database credentials
- API keys / tokens
- User-submitted sensitive documents (e.g., refund proof)

### Demo Media Policy
- `uploads/` contains **limited demo media only** for system demonstration.
- Sensitive content folders are removed.
- A `README.md` is used within media folders to clarify intended usage.

### External Communication Services
Email and SMS services are integrated using third-party providers.
- SMTP and SMS credentials are not committed to the repository
- Secrets are managed outside of version control
- Communication endpoints are protected against misuse through validation
  and request control mechanisms

---

## 6. File Upload & Content Safety (If Applicable)
- Uploaded files are expected to be validated by:
  - file type / extension checks
  - size limits
  - controlled storage paths
- User-uploaded private files are not included in this repository.

---

## 7. AI/ML Module Security Considerations
For the Visual Search module (Python-based):
- AI processing is treated as a separate component to reduce inter-module dependencies.
- Only required scripts and demo assets are included.
- Large datasets, model weights, and generated indexes may be excluded to prevent oversized repositories and accidental data exposure.

---

## Summary
Security in this project is implemented through:
- authentication and session checks
- role-based access control
- server-side validation
- PDO prepared statements
- repository-level protection of secrets and private files

These controls collectively reduce common web security risks and support safe system operation in an e-commerce context.


