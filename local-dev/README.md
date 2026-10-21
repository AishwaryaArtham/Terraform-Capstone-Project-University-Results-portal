# Test the Results Portal on your Mac (no AWS needed)

This folder runs a complete copy of the portal on your own computer using **Docker**.
Nothing here touches AWS, Terraform, or any real password.

## What is Docker, in one minute?

Docker runs small, isolated "mini computers" called **containers**. Instead of installing
WordPress, PHP and MySQL on your Mac, Docker downloads ready-made **images** and runs them:

| Container | What it does | Like on AWS… |
|---|---|---|
| `wordpress` | Apache + PHP + WordPress, with our plugin plugged in | the private EC2 instance |
| `db` | MySQL 8.0 database | RDS MySQL |
| `wpcli` | Runs `wp` commands, then exits | running `wp` over SSM |
| `tests` | Runs the PHP unit tests, then exits | — |

`docker-compose.yml` describes all of these. Your plugin folder
(`wordpress-plugin/university-results`) is **shared live** into the WordPress container, so when you
edit a PHP file and refresh the browser, you see the change immediately.

Data is kept in Docker **volumes** (`db_data`, `wp_data`), so it survives restarts until you wipe it.

---

## Step 1 — Start Docker Desktop

1. Open **Docker Desktop** from Applications.
2. Wait until the bottom-left says **Engine running** (green).
3. Check from a terminal (VS Code: *Terminal → New Terminal*):

   ```bash
   docker info --format '{{.ServerVersion}}'
   ```

   ✅ You should see a version number such as `29.7.2`.
   ❌ `Cannot connect to the Docker daemon` → Docker Desktop isn't running yet.

## Step 2 — Build the local portal (one command)

```bash
cd ~/terraform-wordpress-aws/local-dev
./setup.sh
```

The first run downloads about 1 GB of images (a few minutes). Later runs take ~25 seconds.
It ends with:

```text
Done! Open these in your browser:
  Website:        http://localhost:8080/
  Results page:   http://localhost:8080/?page_id=4
  Admin login:    http://localhost:8080/wp-admin/   (user: local_admin, password: see local-dev/.env)
  Sample codes:   local-dev/access-codes.csv
```

What it did: created `local-dev/.env` (throwaway local passwords), started the containers,
installed WordPress, activated the plugin, created the **Check your results** page, imported the
synthetic students/results from `sample-data/`, published term **2026-S1**, and saved one access
code per sample student in `local-dev/access-codes.csv`.

> `.env` and `access-codes.csv` are git-ignored. They hold fake local values only.

To see the containers: `docker compose ps` (should list `db` and `wordpress` as *running/healthy*).

## Step 3 — Run the automatic checks

```bash
./smoke-test.sh                     # 17 end-to-end checks against the running site
docker compose run --rm tests       # PHP unit tests (validation, CSV, access codes)
```

✅ Expect `Result: 17 passed, 0 failed` and `OK (48 tests, ...)`.

## Step 4 — Check it yourself in the browser

### As the administrator

1. Open <http://localhost:8080/wp-admin/> and log in with `local_admin` and the
   `WP_ADMIN_PASSWORD` from `local-dev/.env`.
2. Left menu → **Results Portal → Students**: 6 sample students, each with *Access code: Set*.
   - **Add a student** with reg. no `UNI2026099` and pick a **Programme** from the dropdown
     (or choose *New programme…* to type one). Try the same number again → *already exists*.
   - Click **Edit** to change a name; **Delete** removes the student *and* their results.
3. **Results Portal → Results**: filter by term. Each row shows %, grade, and whether students can see it.
   - Type `UNI2026001` in **Registration number**: the student's name and existing terms appear,
     and **Term** is set to the latest term (`2026-S2`). The **Subject** dropdowns list only that
     term's subjects; picking a code selects its name and vice versa. For a brand-new term or
     subject, choose *New term…* / *New subject…* and type it.
   - Add a result with marks `120` out of `100` → you get a clear error.
4. **Results Portal → Import CSV**: choose *Results* and upload `sample-data/invalid-examples.csv`.
   The preview marks each bad row in red with the reason. Click **Cancel** (nothing was saved).
5. **Results Portal → Publish & codes**:
   - Term `2026-S1` is **Published**, `2026-S2` is **Not published**.
   - The link to the student results page is shown at the top.
6. Back on **Students**, click **New access code** for `UNI2026001`. The code is shown **once**.
   Write it down — the code in `access-codes.csv` for that student no longer works.

### As a student

Open the **Results page** URL in a *private/incognito* window (so you're not logged in as admin).

| Try this | Expected |
|---|---|
| A reg. no + its code from `access-codes.csv` | That student's **2026-S1** marks, grades, Pass/Fail and overall %. **No 2026-S2**, no other students. |
| Type the code in lower case / without the dash | Still works. |
| `UNI2026006` + its code | One subject shows **Fail** (deliberate in the sample data). |
| Correct reg. no + wrong code | *"We couldn't find results…"* |
| A reg. no that doesn't exist | **Exactly the same** message (the page doesn't reveal who exists). |
| Wrong code 5 times for one reg. no | *"Too many unsuccessful attempts…"* — even the right code is refused for 15 minutes. |

Then, as admin, **Publish** `2026-S2` and look up a student again: both terms now appear.
**Unpublish** it to hide it again.

To clear the 15-minute lockout while testing: `docker compose run --rm wpcli wp transient delete --all`

## Everyday commands

Run these from the `local-dev` folder.

| I want to… | Command |
|---|---|
| Start again after a reboot | `docker compose up -d` (or just `./setup.sh`) |
| Stop (keeps data) | `docker compose stop` |
| See WordPress/PHP errors | `docker compose logs -f wordpress` (Ctrl+C to quit) |
| Run any WP-CLI command | `docker compose run --rm wpcli wp <command>` e.g. `wp urp status` |
| Look at the tables | `docker compose exec db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SELECT * FROM wp_urp_students"'` |
| Get fresh sample codes | `rm access-codes.csv && ./setup.sh` |
| **Wipe everything** and start clean | `docker compose down -v && rm -f access-codes.csv && ./setup.sh` |

## Troubleshooting

| Problem | Fix |
|---|---|
| `port is already allocated` / 8080 busy | Set `WORDPRESS_PORT=8081` in `.env`, then `docker compose down && ./setup.sh`. |
| Browser shows "Error establishing a database connection" | MySQL is still starting; wait 20 s and refresh. `docker compose ps` should show `db` as *healthy*. |
| Forgot the admin password | It's `WP_ADMIN_PASSWORD` in `local-dev/.env`. |
| Everything is weird | Wipe and rebuild (last row of the table above). It only affects local data. |

`wp db query` / `wp db cli` don't work in this setup (the WP-CLI image's MariaDB client can't log in to
MySQL 8). Use `wp eval` or the `docker compose exec db mysql …` command above instead.

## What local testing does **not** prove

It doesn't test the ALB, private subnets, RDS, Secrets Manager, the EC2 bootstrap, HTTPS (local is HTTP
only, like the current ALB), or behaviour across multiple instances. See
[`docs/plugin-deployment.md`](../docs/plugin-deployment.md) for moving the plugin to AWS.
