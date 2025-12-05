
# Bugema CampusShop — Short README

This project is a PHP-based e-commerce web application for Bugema University campus merchandise.

Languages & purpose
- PHP: server-side logic, session handling, database interactions (MySQL via MySQLi).
- HTML: page structure and semantic markup for all pages.
- CSS: styling and responsive layout (in `style.css`).
- JavaScript: client-side interactivity (modals, search autocomplete, cart/favorite actions, chat assistant).
- SQL: database schema and seed data in `campusshop_db.sql`.

How to run (development, Windows + XAMPP)
1. Put the project folder in XAMPP's `htdocs`:

```powershell
# example (run in PowerShell as needed)
Copy-Item -Path . -Destination "C:\xampp\htdocs\Bugema-E-commerce-Platform" -Recurse
``` 

2. Start Apache and MySQL using the XAMPP Control Panel.

3. Create the database and import the schema (`campusshop_db.sql`) via phpMyAdmin or MySQL CLI:

```powershell
# create database (adjust credentials as necessary)
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS campusshop_db;"
# import schema (run from project directory)
mysql -u root -p campusshop_db < .\campusshop_db.sql
```

4. Configure database credentials in `db_connect.php` if needed (default XAMPP user is usually `root` with no password).

5. Open the app in your browser:

```
http://localhost/Bugema-E-commerce-Platform/
```

That's all — this README only documents the languages used, their roles, and how to run the application locally.

**Last updated**: December 5, 2025
### Favorites/Wishlist

