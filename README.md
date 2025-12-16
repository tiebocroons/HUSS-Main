PET Project — PHP Resource Planner

This repository contains a simple PHP-based resource planner that imports the JSON DB files in `db/` into an SQLite database and exposes a tiny API and UI.

Quick steps (PowerShell):

1. Generate SQL and create DB (preferred):

```powershell
php .\php\generate_sql.php
sqlite3 .\php\pet.db < .\php\init.sql
```

Alternatively you can still run the PHP importer to create the DB directly:

```powershell
php .\php\db_init.php
```

2. Start the built-in PHP server from project root and serve the `php` folder:

```powershell
php -S 127.0.0.1:8000 -t .\php
```

3. Open `http://127.0.0.1:8000/index.html` in your browser.

Endpoints:
- `GET /api.php?items=1` — list available item names
- `GET /api.php?item=NAME` — aggregated resource requirements and estimated time

Notes:
- The import script uses a simple heuristic to find nodes with `input`, `output`, and `time` fields in the JSON and inserts them as recipes.
- The aggregator chooses the first recipe found for an item. If multiple production paths exist for the same item we can enhance selection (shortest time, cheapest, etc.).
- By default this uses SQLite (`php/pet.db`) so you don't need a separate MySQL server. If you prefer MySQL I can provide migration instructions.

- MAMP: If you have MAMP installed in the usual locations (`C:\MAMP` on Windows or `/Applications/MAMP` on macOS), the project will auto-detect it and attempt to use MAMP's MySQL credentials (`root` / `root`) and port `8889` by default. Adjust your MAMP MySQL port if yours differs.

Discord bot integration
-----------------------

You can run a small Discord bot that records kill counts to `db/discord_kills.json` and exposes them to the PHP site.

Setup:

- Copy `.env.example` to `.env` and set `DISCORD_TOKEN` (and optionally `DISCORD_ADMIN_IDS`).
- From the project root run in PowerShell:

```powershell
npm install
npm start
```

Notes:

- Commands (use in a guild):
	- `!kill <player|@mention> [count]` — add kills for a player (default +1)
	- `!kills [N]` — show top N kills (default 10)
	- `!killreset <player|@mention>` — admin-only reset for a player (admin IDs via `DISCORD_ADMIN_IDS` or Manage Guild permission)
- The PHP API endpoint is available at `/php/discord_kills_api.php?top=10` (returns JSON).
- To render a small widget on your pages include `php/discord_kills_widget.php` where you want the list displayed:

```php
<?php include __DIR__ . '/php/discord_kills_widget.php'; ?>
```

Permissions:

- Make sure the bot has the `Message Content Intent` enabled in the Discord Developer Portal and that the token in `.env` belongs to a bot invited into your server.
