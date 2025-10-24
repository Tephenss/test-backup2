# Firebase Database Backup Setup Guide

## Overview
This guide will help you set up Firebase Realtime Database as a backup system for your iAttendance application. All database operations (student registration, teacher creation, attendance recording, etc.) will be automatically backed up to Firebase.

## Step 1: Create Firebase Project

1. **Go to Firebase Console**
   - Visit [https://console.firebase.google.com/](https://console.firebase.google.com/)
   - Sign in with your Google account

2. **Create New Project**
   - Click "Create a project" or "Add project"
   - Enter project name: `iattendance-backup` (or your preferred name)
   - Enable Google Analytics (optional)
   - Click "Create project"

3. **Wait for Project Creation**
   - Firebase will set up your project
   - Click "Continue" when ready

## Step 2: Enable Realtime Database

1. **Navigate to Realtime Database**
   - In the left sidebar, click "Realtime Database"
   - Click "Create Database"

2. **Choose Location**
   - Select a location close to your server (e.g., `asia-southeast1`)
   - Click "Next"

3. **Set Security Rules**
   - Choose "Start in test mode" for now
   - Click "Done"

4. **Get Database URL**
   - Copy the database URL (e.g., `https://your-project-id-default-rtdb.firebaseio.com/`)
   - You'll need this for configuration

## Step 3: Create Service Account

1. **Go to Project Settings**
   - Click the gear icon ⚙️ next to "Project Overview"
   - Select "Project settings"

2. **Navigate to Service Accounts**
   - Click "Service accounts" tab
   - Click "Generate new private key"

3. **Download Credentials**
   - Click "Generate key"
   - Download the JSON file
   - **Keep this file secure!**

## Step 4: Configure Your Application

1. **Update Firebase Configuration**
   - Open `config/firebase.php`
   - Replace the placeholder values with your actual Firebase credentials:

   ```php
   return [
       'project_id' => 'your-actual-project-id',
       'private_key_id' => 'your-private-key-id',
       'private_key' => "-----BEGIN PRIVATE KEY-----\nYOUR_ACTUAL_PRIVATE_KEY\n-----END PRIVATE KEY-----\n",
       'client_email' => 'your-service-account@your-project-id.iam.gserviceaccount.com',
       'client_id' => 'your-client-id',
       'database_url' => 'https://your-project-id-default-rtdb.firebaseio.com/',
       // ... other settings
   ];
   ```

2. **Extract Values from JSON**
   - Open the downloaded JSON file
   - Copy the values to your configuration:
     - `project_id` → `project_id`
     - `private_key_id` → `private_key_id`
     - `private_key` → `private_key` (keep the BEGIN/END lines)
     - `client_email` → `client_email`
     - `client_id` → `client_id`
     - `client_x509_cert_url` → `client_x509_cert_url`

## Step 5: Test the Setup

1. **Access Admin Panel**
   - Go to `admin/setup_firebase.php`
   - Login as admin

2. **Test Connection**
   - Click "Test Connection" button
   - You should see "✅ Firebase connection successful!"

3. **Run Initial Backup**
   - Click "Backup All Tables" to backup existing data
   - Monitor the backup status table

## Step 6: Verify Automatic Backups

1. **Test Student Registration**
   - Register a new student
   - Check Firebase console to see the backup

2. **Test Teacher Creation**
   - Create a new teacher in admin panel
   - Verify backup in Firebase

3. **Test Attendance Recording**
   - Record attendance as a teacher
   - Check Firebase for attendance backup

## Step 7: Security Configuration (Recommended)

1. **Update Firebase Security Rules**
   - Go to Realtime Database → Rules
   - Replace with more secure rules:

   ```json
   {
     "rules": {
       "backups": {
         ".read": "auth != null",
         ".write": "auth != null"
       },
       "test": {
         ".read": "auth != null",
         ".write": "auth != null"
       }
     }
   }
   ```

2. **Enable Authentication (Optional)**
   - Go to Authentication → Sign-in method
   - Enable Email/Password authentication
   - Create admin accounts for Firebase access

## Step 8: Monitor Backups

1. **Check Backup Logs**
   - View `logs/firebase_backup.log` for backup operations
   - Monitor for any errors

2. **Firebase Console**
   - Regularly check Firebase console
   - Monitor database usage and costs

3. **Admin Interface**
   - Use `admin/setup_firebase.php` to check backup status
   - Run manual backups when needed

## Troubleshooting

### Common Issues

1. **Connection Failed**
   - Check Firebase configuration values
   - Verify service account has proper permissions
   - Ensure database URL is correct

2. **Backup Not Working**
   - Check PHP error logs
   - Verify Firebase backup log
   - Test individual table backups

3. **Permission Denied**
   - Update Firebase security rules
   - Check service account permissions
   - Verify project ID matches

### Error Messages

- `Failed to get Firebase access token` → Check private key format
- `Failed to backup record` → Check database URL and permissions
- `Connection timeout` → Check network connectivity

## Backup Structure in Firebase

Your Firebase database will have this structure:

```
backups/
├── students_123_1640995200/
│   ├── table: "students"
│   ├── operation: "registration"
│   ├── data: { student data }
│   └── timestamp: "2024-01-01 12:00:00"
├── teachers_456_1640995300/
│   ├── table: "teachers"
│   ├── operation: "creation"
│   ├── data: { teacher data }
│   └── timestamp: "2024-01-01 12:01:00"
└── attendance_789_1640995400/
    ├── table: "attendance"
    ├── operation: "attendance"
    ├── data: { attendance data }
    └── timestamp: "2024-01-01 12:02:00"
```

## Maintenance

1. **Regular Monitoring**
   - Check backup logs weekly
   - Monitor Firebase usage monthly
   - Test backup system quarterly

2. **Data Cleanup**
   - Consider archiving old backups
   - Set up automatic cleanup rules
   - Monitor storage costs

3. **Updates**
   - Keep Firebase SDK updated
   - Review security rules regularly
   - Test after any system updates

## Support

If you encounter issues:
1. Check the error logs first
2. Verify Firebase configuration
3. Test with a simple backup operation
4. Check Firebase console for errors

## Cost Considerations

- Firebase Realtime Database has a free tier
- Monitor usage to avoid unexpected charges
- Consider data archiving for long-term storage
- Set up billing alerts in Firebase console

---

**Note**: This backup system is designed to be non-intrusive. If Firebase backup fails, your main application will continue to work normally. The backup system logs errors but doesn't interrupt normal operations.







