# Deploy on Railway

This repository includes a PHP/Apache Dockerfile. Railway builds it from the
repository root. Use a separate Railway MySQL service for the database.

1. Create a Railway project. Add a **MySQL** database service.
2. Add a service from the GitHub repository
   `jaizoncabusaol-create/furniture`, branch `main`.
3. In the web service **Variables**, add these reference variables. Replace
   `MySQL` in the references if your database service has another name:

   ```text
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_NAME=${{MySQL.MYSQLDATABASE}}
   DB_USER=${{MySQL.MYSQLUSER}}
   DB_PASS=${{MySQL.MYSQLPASSWORD}}
   ADMIN_EMAIL=your-admin-email@example.com
   ADMIN_PASSWORD=choose-a-unique-password-at-least-12-characters
   ```

   Set a real admin password in Railway; never commit it to Git. The Docker
   image already sets `APP_ENV=production` and `AUTO_CREATE_DATABASE=false`.
   The database tables and catalog are created on first request. A fresh
   production database creates the admin account from `ADMIN_EMAIL` and
   `ADMIN_PASSWORD`. It also creates the default `user` / `123` customer
   account when that account does not exist. The demo Google button is disabled.
   Changing these variables later does not reset an existing admin password.

4. Attach a **volume** to the web service with mount path `/data`. This keeps
   uploaded images and downloadable backups across deployments. The container
   copies catalog images into the volume on startup. Do not mount the volume
   over `/var/www/html` or `/opt/catalog`.
5. In the web service settings, set **Healthcheck Path** to `/health.php`.
   The endpoint checks the MySQL connection and returns 503 if setup fails.
6. Under **Networking > Public Networking**, select **Generate Domain**. The
   container listens on Railway's `PORT`; no custom start command is needed.
7. Open the domain and log in with the admin email and password above.

The repository does not contain the local XAMPP database or customer uploads.
For an existing installation, import a private database backup into Railway
MySQL and copy its uploads to the `/data/uploads` volume separately. Keep
database backups private. The app's backup files are stored in `/data/backups`.
