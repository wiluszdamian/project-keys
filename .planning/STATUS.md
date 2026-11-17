# Status

Aktualizuje Claude Code po każdej fazie.

## Serwer

- Dostawca: Contabo
- Adres: 155.133.197.67 (alias SSH `project-keys`, użytkownik `deploy`, klucz `~/.ssh/id_ed25519_project_keys`)
- CPU / RAM / dysk: 4 vCPU AMD EPYC, 7.8 GB RAM, 99 GB dysk, swap 2 GB
- System: Debian 13 (trixie), kernel 6.12
- Docker / Compose: Docker 29.10.0, Compose v5.8.0
- Domena: (uzupełnić lub „brak, tunel SSH”)

## Postęp

| Faza | Status | Data | Uwagi |
|---|---|---|---|
| 0 Serwer | gotowe | 2026-11-17 | SSH tylko kluczem jako deploy, ufw 22/80/443, fail2ban, unattended-upgrades, Docker |
| 1 Szkielet | do zrobienia | | |
| 2 Dane i API | do zrobienia | | |
| 3 Otodom | do zrobienia | | |
| 4 OLX | do zrobienia | | |
| 5 Harmonogram | do zrobienia | | |
| 6 AI | do zrobienia | | |
| 7 Powiadomienia | do zrobienia | | |
| 8 Utrwalenie | do zrobienia | | |

## Decyzje

(data, decyzja, powód)

- 2026-11-17: PHP 8.5 i najnowszy Laravel zamiast PHP 8.4. Prośba właściciela.
- 2026-11-17: Serwer ma Debian 13 zamiast Ubuntu 24.04. Docker z oficjalnego repozytorium dla Debiana, reszta kroków bez zmian.
- 2026-11-17: `deploy` ma sudo bez hasła (NOPASSWD), bo logowanie jest tylko kluczem i użytkownik nie ma hasła.
- 2026-11-17: Ustawienia SSH w `/etc/ssh/sshd_config.d/00-hardening.conf`, usunięty `50-cloud-init.conf`, który włączał logowanie hasłem.

## Otwarte pytania

- Czy warunki OpenCode Go pozwalają na użycie z własnej aplikacji?
- Dokładne ID modelu DeepSeek w OpenCode Go.
- Domena dla HTTPS.
