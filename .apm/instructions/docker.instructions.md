---
description: Docker development environment setup and usage
applyTo: "**/*"
---

# Docker Dev Environment

- `task up` auto-generates `.docker/.env` if missing — no manual step needed
- `.docker/.env` contains `COMPOSE_PROJECT_NAME`, `WEB_PORT`, `DB_PORT` with deterministic ports hashed from the directory name
- To regenerate (e.g., after renaming/copying a worktree): `rm .docker/.env && task up`
- If ports conflict with another worktree, stop the conflicting services first (`task down` in that worktree)
- Default admin credentials: `admin`/`admin`
- Use `task up`/`task down` to manage services (see Commands)
