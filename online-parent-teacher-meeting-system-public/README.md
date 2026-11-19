# Online Parent-Teacher Meeting System

A PHP and MySQL-based web application designed to support communication and meeting coordination between parents, teachers, and administrators.

## Technologies
- PHP
- MySQL
- HTML
- CSS
- JavaScript (where used in the application)

## Main application areas
- Administrator portal
- Parent portal
- Teacher portal
- Meeting and booking management
- Student and teacher management
- Attendance and academic information
- Feedback and messaging
- Fee and payment management

## Privacy and repository contents
This public portfolio copy intentionally does **not** include:
- The MySQL database or SQL export
- The actual database connection configuration
- Uploaded student documents
- Database backup or database-testing scripts

The database is private and is not distributed with this repository. Application pages that require database access will not run until a database is independently configured.

## Local configuration
1. Install a local PHP/MySQL environment such as XAMPP.
2. Create your own database named `online_ptm`.
3. Configure the database schema privately; no SQL dump is included here.
4. Copy `config/database.example.php` to `config/database.php`.
5. Edit `config/database.php` with your own local database credentials.
6. Open the application through your local web server.

**Important:** Keep `config/database.php` private. It is excluded by `.gitignore`.

## Portfolio note
This project is presented as an academic software project. It is shared to demonstrate application structure and development work; it is not represented as paid client work.
