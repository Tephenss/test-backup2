# iAttendance Management System

A comprehensive attendance management system for educational institutions, featuring separate interfaces for teachers and students with email verification security.

## Features

### Authentication & Security
- Two-factor authentication via email verification
- Secure password hashing
- Session management
- Login attempt logging
- Role-based access control (Teacher/Student)

### Teacher Features
- Dashboard with activity overview
- Student Management
  - Add/Edit/Remove students
  - Assign students to sections
- Attendance Management
  - Take attendance for classes
  - View attendance records
  - Generate attendance reports
- Marks Management
  - Record student marks
  - View and update grades
- Timetable Management
  - Create and manage class schedules
  - View weekly timetable
- Reports Generation
  - Attendance reports
  - Performance reports
  - Export functionality

### Student Features
- Dashboard with key metrics
  - Today's classes
  - Attendance rate
  - Average grade
  - Upcoming tests
- Attendance Tracking
  - View personal attendance records
  - Check attendance history
- Marks Viewing
  - View grades and performance
  - Track academic progress
- Timetable Access
  - View class schedule
  - Check upcoming classes

## Technical Stack
- PHP 7.4+
- MySQL/MariaDB
- Bootstrap 5
- PHPMailer for email functionality
- PDO for database operations
- Modern JavaScript (ES6+)

## Installation

1. **Prerequisites**
   - XAMPP (Apache, MySQL, PHP)
   - Composer (for PHP dependencies)
   - Modern web browser (Chrome recommended)

2. **Setup**
   ```bash
   # Clone the repository
   git clone [repository-url]

   # Install dependencies
   composer install

   # Configure database
   - Import database/schema.sql to your MySQL server
   - Update config/database.php with your credentials

   # Configure email
   - Update config/email.php with your SMTP settings
   - For Gmail, use App Password for SMTP_PASSWORD
   ```

3. **Configuration**
   - Set up your web server (Apache) to point to the project directory
   - Ensure PHP has write permissions for logs directory
   - Configure your email settings in config/email.php

## Security Features
- Email verification for login
- Password hashing using PHP's password_hash()
- Prepared statements for all database queries
- Session management and validation
- Input sanitization and validation
- XSS protection
- CSRF protection

## Usage

### Teacher Login
1. Visit the login page
2. Select "Teacher" as user type
3. Enter credentials
4. Complete email verification
5. Access teacher dashboard

### Student Login
1. Visit the login page
2. Select "Student" as user type
3. Enter credentials
4. Complete email verification
5. Access student dashboard

## Contributing
1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License
This project is licensed under the MIT License - see the LICENSE file for details.

## Support
For support, please contact the system administrator or create an issue in the repository.

## System Reviewer & Defense Guide

### System Overview
- **iAttendance** is a web-based attendance management system for schools, with separate portals for Admin, Teachers, and Students.
- Built for easy attendance tracking, class management, and academic reporting.

### Technology Stack
- **Backend:** PHP 7.4+
- **Database:** MySQL/MariaDB
- **Frontend:** Bootstrap 5, HTML5, CSS3, JavaScript (ES6+)
- **Email:** PHPMailer (for email verification, notifications)
- **PDF Generation:** DomPDF (for printable reports)
- **Dependency Management:** Composer
- **Database Access:** PDO (prepared statements for security)

### Key Libraries & Frameworks
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) (email sending)
- [DomPDF](https://github.com/dompdf/dompdf) (PDF export)
- [Bootstrap 5](https://getbootstrap.com/) (UI framework)
- [Bootstrap Icons](https://icons.getbootstrap.com/) (icon set)

### Security Features
- Email verification (2FA)
- Password hashing (bcrypt)
- Session management
- Prepared statements (SQL injection protection)
- Input validation & sanitization
- XSS & CSRF protection

### System Modules
- **Admin:**
  - Manage teachers, students, sections, subjects, timetable
  - Approve student applications
  - Promote students to next year level
  - Dashboard with stats and recent activity
- **Teacher:**
  - Manage students in their classes/sections
  - Take and review attendance
  - Record and update grades
  - View and print reports
  - Timetable management
- **Student:**
  - View attendance, grades, and timetable
  - Update profile

### Database Design
- Relational structure: students, teachers, classes, sections, subjects, attendance, marks, etc.
- Uses foreign keys for data integrity
- All queries use PDO prepared statements

### Deployment & Requirements
- XAMPP/WAMP/LAMP stack (Apache, MySQL, PHP)
- Composer for PHP dependencies
- SMTP server for email (Gmail, etc.)

### For Defense/Panelist Q&A
- **What makes your system secure?**
  - Email verification, password hashing, prepared statements, session validation, XSS/CSRF protection.
- **How is attendance tracked?**
  - Teachers mark attendance per class; students can view their records.
- **How are students promoted?**
  - Admin can promote students in bulk; 4th years are marked as graduates.
- **How are reports generated?**
  - Teachers/Admin can export attendance/grades as PDF (DomPDF).
- **What happens if a student/teacher is deleted?**
  - Related records (attendance, marks, enrollments) are handled to maintain integrity.
- **How is the UI built?**
  - Bootstrap 5 for responsive design, Bootstrap Icons for visuals, custom CSS for branding.
- **How is email handled?**
  - PHPMailer via SMTP (configurable in config/email.php).

---
**Tip:** Be ready to demo login, attendance marking, student promotion, and PDF export. Know your database structure and security features! 