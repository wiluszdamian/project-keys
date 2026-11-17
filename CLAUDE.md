# project-keys

Prywatny serwis do wyszukiwania mieszkań. Plan i zasady pracy są w `.planning`, zacznij od `.planning/CLAUDE_INSTRUCTIONS.md`. Postęp w `.planning/STATUS.md`.

- Laravel 13, PHP 8.5, Docker Compose (app, worker, scheduler, postgres, redis, caddy).
- Serwer deweloperski: `ssh project-keys` (użytkownik `deploy`), kod w `/home/deploy/project-keys`.
- Testy: `docker compose exec app php artisan test` na serwerze.
- Commity po angielsku z prefiksem (`feat:`, `fix:`, `chore:`), na branchu `develop`.
