## Hosting Guide

### 1. Upload files

Upload these files/folders to your hosting public directory such as `public_html/`:

- `index.php`
- `admin.php`
- `user.php`
- `db.php`
- `uploads/`
- `backup.php`
- `config.example.php`

Do not upload `users.json`, local backups, database exports, or customer uploads.

### 2. Create the database

In your hosting panel:

1. Create a MySQL database
2. Create a MySQL user
3. Assign the user to the database with all privileges

### 3. Create the app tables

The app creates its tables and starter catalog on first use. For an existing
installation, import your own private backup through phpMyAdmin before opening
the site. Do not publish database backups in the repository.

### 4. Create the production config

Copy:

- `config.example.php`

to:

- `config.php`

Then edit `config.php` and replace:

- `db_name`
- `db_user`
- `db_pass`
- `db_host` if needed

Important:

- keep `'auto_create_database' => false` when the hosting database already exists

### 5. Make uploads writable

Your `uploads/` folder must be writable by the server.

Recommended permission:

- `755` or `775`

If your hosting requires it, use:

- `777` only as a last resort

### 6. Open the site

Visit your domain and test:

- login
- register
- add product
- upload product image
- chat
- orders

### 7. Default notes

If your imported database is empty, the system can still ensure base accounts when the app runs.

Default demo accounts in the codebase are:

- Admin: `admin@demo.local`
- User: `user@demo.local`
- Password: `123`

Change these after going live.
