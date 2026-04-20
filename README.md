# School Management System

A lightweight PHP + MySQL school management portal for M M Maheta High School.

## Overview

This project provides:
- Admin and teacher login
- Student, teacher, class, and enrollment management
- Student attendance
- Teacher attendance
- Role-based navigation and access control

## Tech Stack

- PHP (plain PHP, no framework)
- MySQL (via PDO)
- HTML/CSS
- XAMPP-friendly local setup

## Project Structure

- `index.php` - main app shell and role-based navigation
- `login.php` - admin/teacher login page
- `db.php` - DB connection, runtime schema checks, auth/session helpers
- `schema.sql` - base SQL schema for fresh database setup
- `pages/` - module pages (`dashboard`, `students`, `teachers`, `classes`, `enrollments`, attendance pages)
- `assets/` - styles and icons

## Role Access

### Admin

Visible navigation:
- Dashboard
- Students
- Teachers
- Classes
- Enrollments

Admin can also access:
- Student Attendance page
- Teacher Attendance page

### Teacher

Visible navigation:
- Dashboard
- Students
- Attendance

Teacher access is limited to:
- Assigned standard only
- Student attendance for assigned standard only

## Login Rules

- `login.php` has two roles: `Admin Login` and `Teacher Login`.
- Teacher signs in with `Teacher Login ID` and password provided by admin.
- Teacher accounts are created/managed from the Teachers page by admin.

Default admin credentials (fresh DB):
- Username: `admin`
- Password: `admin123`

## Attendance Rules

### Student Attendance

- Each student row shows two options: `Present` and `Absent`.
- UI ensures only one option is active at a time.
- Teacher can mark attendance only for assigned standard.

### Teacher Attendance

- Admin marks teacher attendance from `pages/teacher_attendance.php`.
- Status values are limited to `Present` or `Absent`.

## Database Setup

1. Start Apache and MySQL in XAMPP.
2. Create database:
   `school_management`
3. Import schema:
   `schema.sql`
4. Verify credentials in `db.php`:
   - host: `127.0.0.1`
   - database: `school_management`
   - user: `root`
   - password: ``

## Runtime Schema and Migration Behavior

`db.php` automatically runs runtime schema checks on connection.

Current attendance schema uses:
- `student_attendance.status` -> `ENUM('Present','Absent')`
- `teacher_attendance.status` -> `ENUM('Present','Absent')`

If old data/schema exists, runtime migration will:
- Convert non-supported status values to `Absent`
- Alter attendance status columns to the new enum type

## Main Tables

- `admin_users`
- `students`
- `teachers`
- `teacher_accounts`
- `classes`
- `enrollments`
- `student_attendance`
- `teacher_attendance`

## Run the Application

Open in browser:

`http://localhost/ProjectX/school-management/login.php`

## Quick Validation Commands

From project root:

```bash
php -l db.php
php -l index.php
php -l login.php
php -l pages/student_attendance.php
php -l pages/teacher_attendance.php
```

## Notes

- Navigation is role-based.
- Teacher UI is intentionally restricted for data safety.
- Attendance flow is now standardized to only `Present` and `Absent`.
