---
name: warn-exception-in-services
enabled: true
event: file
action: warn
conditions:
  - field: content
    operator: regex_match
    pattern: throw new .*Exception
  - field: file_path
    operator: regex_match
    pattern: src/(Service|Validation)/.*\.php$
---

**Result Pattern Violation — Use `Result::fail()` Instead of Exceptions**

Expected validation failures (invalid input, hierarchy violations, business rules) return `Result::fail(ValidationError ...)` — see the Result Pattern rules in CLAUDE.md (PHP Conventions). Throwing for them breaks the controller's error-handling flow and produces 500 errors instead of structured JSON error responses.

Exceptions stay appropriate for infrastructure failures and impossible states, and for `Transactional`'s internal rollback signal, which never leaves the class.

Examples: `GridElementService`, `ElementPlacementService`, `SharedBlockService` (src/Service) and `ReorderValidator` (src/Validation).
