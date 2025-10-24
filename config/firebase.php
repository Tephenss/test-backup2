<?php
/**
 * Firebase Configuration
 * 
 * This file contains Firebase configuration settings for database backup.
 * Replace the placeholder values with your actual Firebase project credentials.
 */

return [
    // Firebase Project Configuration
    'project_id' => 'iattendance-backup-115dc',
    'private_key_id' => '9ed03629883ccbbe69a15f770fdcc6c973f701c8',
    'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCgUXye+Kp+BwSp\n5DtOgHqSeqEC+ECuicJVdwVDK2dpB8De3WrD+8cixaf7UhtQvxJ4hlyxHpwhodgf\nlEf5g88B2Rd1nGx0VNmwWJ7e4HKpXCR8/u8eAlHX5imk59GqP8u7vaD1N9TIRCxS\nbcohdKkC448jG71rUzm25EXV2dNQ7CE0kmUNpXlNXuetJ0ZPwIabjlOg02VDfrmI\n4vAp9JaRMmJPTTT1ccmbE3Lu+LszAlmXzzqbanGzyYYbv1Z2R4lhQFVcuq41kEwX\n5k/dJiyf0wETeKcNOv4xc++K08ffh9c1KaZ2pkdgw9ELHGdng7B/OL5teZA7gSV0\nA4ahQaIDAgMBAAECggEAFantRS4lG9Km9q44tWr3JUQc7eHOG8fR0uq6c1EyMCc0\nEOmqu8ESL8i14mg9+LNZM6A0dlrEjmboZZwL/dOp7X4AhYwVu8KbiBDxpvx9ghVJ\nePqaJVv640ne5sHMu0zToxME5R4eStGri5f6cHrrO9C0TvI4roAYlHZUWYmN3KlV\nAGrSk5vNNdYXEbxR8UHzBe8+6yF4MMvW93ZWYMabMzWyD6ltZhNHDyPPe7E6ZsOl\n7PuzwWc+9HtmW/UAMoSpovW+ed2r14/J0IuBbWtmNsiOH6N9W9MUm39Ti9GbHOSO\nAYgmdXsNaSN8/V4o/Z4IjDKP/U0dxT5QzjpQkHJuQQKBgQDchbErtBoyo0R2KlDy\nLqQD1Y2gzLZjg/URiRCzuI7oeMm1QFTEDhZZysbLHc8DT34GDG/EZwRvq8g8RFzh\nw54KPmDx6VjVvAkKsphEqYNPboOFvKspVMYnrlRcViJkq4DtvJfxI1SVtZHryRfh\nEPJcEqWolVd47s3xRvxEn1ZSpwKBgQC6HETPBH0lUPl2mo7+3bQXPjCo0N1QxapF\nKF5wgEjjsbSbVAPbhudt5MxZNFnf5WxfFsXi+n9viecTDWa6yEJ3vzgmLeK4pDSo\nS3tQFtIqmaRxKIFDdsRgnOkGviQfBrXjg33SEdSASkXWbspLuKX27q571PqwUpg0\nC2f1JpotRQKBgQCVQFb9QVRJ2X7IizNl9nNDtRG6N1NqXzFKwP3w5YSziqzaY8P7\nPZ2bAEczyeUGVJLy/Q/YWfECs70+LPbARml8fWOX11ssratg9idgsWoSJLYXme4u\ndxX2XWLza8izYfBM90vPBR6GhPFSKDRlO9cMwgIk647cZqQ0HNs4hq8iGQKBgDc3\nc+LHvil4IMtjh9FuDaRnuyAa986jFqV2GK7gIMANVTxQbOSQ3dDo9QfyVEftVX3Q\nz91L3MtG6tvoOfZou++zOAF706xca5MS8f8NBkXFV9iK3+8YKaNQaoKpnyXlY8mg\nlY/h4l49qwK31CUrH3Jn1jS/N7Fgj+/BApLlZRDRAoGBAIGz/7y8j3hNPdiTphct\n4V52Pma2Zhj6JRaQbKm3My2zqb8eDMA5771bznRYtcOjQvnmzj9ELAefIPvGOO/s\nKmAMD76YJFRpldU5h22KqaM7rRV+IifsOvOPd1HzEp/QMXz9FIyERVd6ud8BKb7W\nQPKS+HAgSLtfHT4rQvA78F+h\n-----END PRIVATE KEY-----\n",
    'client_email' => 'firebase-adminsdk-fbsvc@iattendance-backup-115dc.iam.gserviceaccount.com',
    'client_id' => '117841103796311872628',
    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
    'token_uri' => 'https://oauth2.googleapis.com/token',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-fbsvc%40iattendance-backup-115dc.iam.gserviceaccount.com',
    
    // Firebase Database URL
    'database_url' => 'https://iattendance-backup-115dc-default-rtdb.asia-southeast1.firebasedatabase.app/',
    
    // Backup Settings
    'backup_enabled' => true,
    'backup_tables' => [
        'students',
        'teachers', 
        'admins',
        'attendance',
        'marks',
        'classes',
        'subjects',
        'sections',
        'courses',
        'activity_logs',
        'login_logs',
        'verification_codes',
        'class_students',
        'subject_assignments',
        'timetable',
        'assessment_types',
        'semester_settings'
    ],
    
    // Retry settings
    'max_retries' => 3,
    'retry_delay' => 1000, // milliseconds
    
    // Logging
    'log_backup_operations' => true,
    'log_file' => '../logs/firebase_backup.log'
];
?>
