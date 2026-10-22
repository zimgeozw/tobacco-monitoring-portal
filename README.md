# TIMB Tobacco Crop Monitoring Portal

A web portal for satellite-detected tobacco fields in Zimbabwe. It shows hectarage, field counts and estimated yield by season and district, on a satellite map with filters and Excel downloads.

Designed by [Zimgeo](https://www.zimgeo.co.zw). Live at **https://timb.zimgeo.co.zw**.

## What it does

- **Dashboard:** a season heading (e.g. *2025-2026 Agriculture Season*), KPI cards, and a satellite map. The map shows a density heatmap at province scale, field dots at district scale, and field outlines when zoomed in, like the Earth Engine view. It also has district boundaries, optional training points, charts, a district summary and a sortable field table.
- **Filters:** district, field size, NDVI and field ID. The map, KPIs, charts, tables and downloads all follow the filters.
- **Downloads:** Excel (Fields sheet with filter dropdowns, District summary, Field sizes, About), CSV, and GeoJSON for GIS.
- **Users:** the admin adds people by email, and each person gets an invitation to set their own password. Viewers can also be limited to view-only (no downloads) or to selected districts. Password reset works by email.
- **Email notifications:** invitations, password resets, password-change alerts, *new season published* (users can opt out), and admin alerts for account activations and completed imports.
- **Admin:** seasons (heading, dates, yield benchmark, draft/publish), chunked upload of large CSV or ZIP files with resumable import, users, an activity log, and an email test.

## Requirements (cPanel)

- PHP 8.1+ with `pdo_mysql`, `zip`, `mbstring` and `openssl`. Set these in cPanel → Select PHP Version.
- MySQL 5.7+ or MariaDB 10.3+.
- An email account for sending mail, e.g. `noreply@zimgeo.co.zw`.

No Composer, Node or build step is needed. MapLibre and Chart.js are already included in `assets/vendor/`.

## First deployment

1. **Database:** cPanel → MySQL® Databases. Create the database `zimgeoco_timb` and a user, then add the user to the database with ALL PRIVILEGES.
2. **Email:** cPanel → Email Accounts. Create `noreply@zimgeo.co.zw`.
3. **Git:** cPanel → Git™ Version Control → Create.
   - Clone URL: `git@github.com:zimgeozw/tobacco-monitoring-portal.git`. The repo is private, so first add the cPanel SSH public key as a **Deploy key** in GitHub → repo Settings → Deploy keys.
   - Repository path: `/home/zimgeoco/repositories/tobacco-monitoring-portal`
4. **Deploy path:** check that `DEPLOYPATH` in `.cpanel.yml` matches the document root of `timb.zimgeo.co.zw`, shown in cPanel → Domains. The default is `/home/zimgeoco/timb.zimgeo.co.zw/`.
5. cPanel → Git → Manage → **Pull or Deploy** → *Deploy HEAD Commit*.
6. Open **https://timb.zimgeo.co.zw/install.php** and enter the database, administrator and SMTP details. This writes `config.php`, which is never committed to Git, and creates the tables. The installer disables itself afterwards.
7. Sign in, go to **Administration → Activity & email**, and send a test email.

## Updating the code

Push to `main` on GitHub. Then in cPanel → Git → Manage, click **Update from Remote** and **Deploy HEAD Commit**. `config.php` and `storage/` are kept on every deploy.

## Adding a season's data

1. In Earth Engine, open `gee/tobacco_field_export.js`. Set `SEASON`, `START_DATE` and `END_DATE`, and make sure that season's training points are imported. Run it, then run the three export tasks in the **Tasks** tab.
2. Download the CSVs from Google Drive → `Tobacco_Exports`. Zip the large Fields CSV: 119 MB becomes about 18 MB.
3. Portal → Administration → **Seasons** → *New season*. Enter a heading such as `2026-2027 Agriculture Season`.
4. **Upload data**: drop the Fields, RunInfo and (optional) TrainingPoints files. Keep the page open until each file shows ✓.
5. Preview the season on the dashboard (it is a draft, visible to admins only), then **Publish**. Users who opted in receive an email.

Notes:
- Each field is assigned to its **current district** (geoBoundaries ADM2) from its centre point, so Kadoma is split into Mhondoro-Ngezi, Sanyati and Kadoma Urban. The Earth Engine (GAUL) district is kept as `district_gee`.
- Estimated yield = area × the season's benchmark (t/ha). If you edit the benchmark under Seasons, every field is recalculated.

## Structure

```
index.php            Dashboard
login/forgot/reset/account.php
admin/               Overview, seasons, upload, users, activity & email
api/                 JSON endpoints, exports, chunked upload + import
app/                 Bootstrap, auth, mailer (SMTP), importer, XLSX writer, geo helpers
assets/              CSS, JS, TIMB logo, boundary GeoJSON, vendored MapLibre / Chart.js
gee/                 Earth Engine export script
sql/schema.sql       Database schema (applied by install.php)
storage/             Uploads, cache, logs (not web-accessible, not in Git)
```

## Data credits

Imagery: Esri World Imagery. Streets: © OpenStreetMap contributors, © CARTO. Boundaries: geoBoundaries (CC BY 4.0). Field detection: Copernicus Sentinel-2 via Google Earth Engine.

---
© Zimgeo (Map Tech Pvt Ltd) · www.zimgeo.co.zw
