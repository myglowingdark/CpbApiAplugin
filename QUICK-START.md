# Quick Start Checklist - Secondary Database Setup

## ✅ Pre-Setup Checklist

- [ ] Backup your current WordPress database
- [ ] Verify you have access to create new MySQL databases
- [ ] Note down your MySQL root or admin credentials

## 📝 Step-by-Step Setup

### 1️⃣ Create Secondary Database (5 minutes)

In phpMyAdmin, MySQL Workbench, or command line:

```sql
-- Create the database
CREATE DATABASE cpb_secondary_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user (optional, can use existing)
CREATE USER 'cpb_secondary'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD';

-- Grant privileges
GRANT ALL PRIVILEGES ON cpb_secondary_db.* TO 'cpb_secondary'@'localhost';
FLUSH PRIVILEGES;
```

**Save these credentials** - you'll need them in the next step!

### 2️⃣ Configure in WordPress (2 minutes)

1. Go to **WordPress Admin → CPB API Sync → Secondary Database**
2. Fill in the form:
   - Database Host: `localhost`
   - Database Name: `cpb_secondary_db`
   - Database Username: `cpb_secondary`
   - Database Password: (the password you created)
   - Character Set: `utf8mb4` (leave default)

3. Click **Test Connection** button
4. If successful (green ✓), click **Save Settings**

### 3️⃣ Enable Feature (1 minute)

1. Check the box: **☑ Enable Secondary Database**
2. Click **Save Settings**
3. Tables will be created automatically

### 4️⃣ Move Existing Data (Optional)

**Only if you have existing colleges/courses:**

⚠️ **IMPORTANT: This will DELETE data from your primary database after copying!**

1. **Backup your primary database first!**
2. On same page, find **Data Migration** section
3. Click **Move Data Now (Delete from Primary)**
4. Confirm the warning dialog
5. Wait for completion message

## ✨ You're Done!

### What happens now?

- ✓ All **new** colleges and courses are saved to the secondary database
- ✓ All **existing** colleges and courses (if migrated) are **moved** to the secondary database
- ✓ Your primary database is **freed up** - college/course data is **removed** after migration
- ✓ Everything works exactly as before - no code changes needed!

### Verify It's Working

1. Go to **Colleges → Add New** or **Courses → Add New**
2. You should see a blue info notice: "Secondary Database Active"
3. Create a test college or course
4. Check your secondary database - you'll see the new data there!

## 🎯 Quick Reference

### Settings Location
**WordPress Admin → CPB API Sync → Secondary Database**

### What Gets Moved?
- College post type + all metadata (deleted from primary after copy)
- Course post type + all metadata (deleted from primary after copy)

### What Stays?
- Everything else (posts, pages, users, settings, other CPTs)

### Connection Status Colors
- 🟢 **Green** = Connected and working
- 🔴 **Red** = Connection failed - check credentials
- 🔵 **Blue** = Disabled (using primary database only)

## 🆘 Quick Troubleshooting

### "Connection Failed" Error

Try these in order:

1. **Check database exists:**
   ```sql
   SHOW DATABASES LIKE 'cpb_secondary_db';
   ```

2. **Verify credentials** - re-enter them in settings

3. **Try `127.0.0.1`** instead of `localhost` for host

4. **Check user privileges:**
   ```sql
   SHOW GRANTS FOR 'cpb_secondary'@'localhost';
   ```

### Migration Hangs or Times Out

- Check PHP `max_execution_time` (increase if needed)
- Check MySQL `max_allowed_packet` size
- Try migrating in smaller batches (contact developer)

### "Tables Not Created" Error

```sql
-- Grant CREATE permission explicitly
GRANT CREATE ON cpb_secondary_db.* TO 'cpb_secondary'@'localhost';
FLUSH PRIVILEGES;
```

## 📊 Monitor Your Databases

### Check database sizes:

```sql
-- Size of primary database
SELECT 
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
FROM information_schema.TABLES 
WHERE table_schema = 'your_primary_db_name'
GROUP BY table_schema;

-- Size of secondary database  
SELECT 
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)'
FROM information_schema.TABLES 
WHERE table_schema = 'cpb_secondary_db'
GROUP BY table_schema;
```

You should see the primary database size reduced!

## 🔐 Security Tips

1. ✓ Use a **strong, unique** password for the secondary database user
2. ✓ Grant **only necessary** privileges (not root/admin)
3. ✓ **Backup both databases** regularly
4. ✓ Keep credentials **secure** and documented

## 📚 Full Documentation

For detailed information, see: [SECONDARY-DATABASE-GUIDE.md](SECONDARY-DATABASE-GUIDE.md)

## 🎉 Benefits You'll See

- **Reduced primary database size** - Lighter, faster queries
- **Better organization** - Content types separated logically  
- **Improved performance** - Less table locking contention
- **Easier scaling** - Can move secondary DB to different server later
- **Flexible backups** - Back up college/course data separately

---

**Need Help?** Check the full guide in `SECONDARY-DATABASE-GUIDE.md` or contact support.
