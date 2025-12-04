# cPanel Cron Job Setup Guide

## Quick Setup (5 Minutes)

### Step 1: Get Your Account Information
From the cPanel page you're viewing:
- **Username**: `feyh9rczl7jz` (shown in the URL)
- **Hosting Path**: `/home/feyh9rczl7jz/public_html/`

### Step 2: Determine Your Script Path
If you uploaded CarVendors Scraper to a folder like:
```
/home/feyh9rczl7jz/public_html/carvendors-scraper/
```

Then your **full command** will be:
```
/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php
```

---

## Cron Job Configuration

### Option A: Run Twice Daily (6 AM & 6 PM) ⭐ RECOMMENDED

**Fill in these fields in cPanel:**

| Field | Value |
|-------|-------|
| **Minute** | `0` |
| **Hour** | `6,18` |
| **Day** | `*` (all days) |
| **Month** | `*` (all months) |
| **Weekday** | `*` (all weekdays) |
| **Command** | `/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log 2>&1` |

**Explanation:**
- `0` = At minute 0 (top of the hour)
- `6,18` = At 6 AM AND 6 PM (24-hour format)
- `>> logs/cron.log` = Append output to log file
- `2>&1` = Include errors in the log

---

## Cron Job Options

### Option B: Run Once Daily (6 AM)

| Field | Value |
|-------|-------|
| **Minute** | `0` |
| **Hour** | `6` |
| **Day** | `*` |
| **Month** | `*` |
| **Weekday** | `*` |
| **Command** | `/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log 2>&1` |

### Option C: Run Every 6 Hours (4x Daily)

| Field | Value |
|-------|-------|
| **Minute** | `0` |
| **Hour** | `*/6` |
| **Day** | `*` |
| **Month** | `*` |
| **Weekday** | `*` |
| **Command** | `/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log 2>&1` |

### Option D: Run Every 12 Hours

| Field | Value |
|-------|-------|
| **Minute** | `0` |
| **Hour** | `*/12` |
| **Day** | `*` |
| **Month** | `*` |
| **Weekday** | `*` |
| **Command** | `/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log 2>&1` |

### Option E: Hourly

| Field | Value |
|-------|-------|
| **Minute** | `0` |
| **Hour** | `*` |
| **Day** | `*` |
| **Month** | `*` |
| **Weekday** | `*` |
| **Command** | `/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log 2>&1` |

---

## Email Notifications

**Current Email**: `feyh9rczl7jz` (the default)

### To Disable Email Notifications
If you don't want to receive emails for every cron run, modify the command to redirect output to `/dev/null`:

```bash
/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >/dev/null 2>&1
```

### To Enable Email Notifications
Keep the command as-is (with `>> logs/cron.log`). cPanel will email you only if there's an error.

---

## Step-by-Step Instructions (cPanel UI)

1. **Open cPanel** → Navigate to **Cron Jobs**
2. **Scroll to "Add New Cron Job"**
3. **Fill in the Common Settings:**
   - Minute: `0`
   - Hour: `6,18` (or choose your option above)
   - Day: `*`
   - Month: `*`
   - Weekday: `*`
4. **Paste Command:**
   ```
   /usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log 2>&1
   ```
5. **Click "Add New Cron Job"**
6. **Verify** it appears in "Current Cron Jobs" list below

---

## Verify Setup

### Check Cron Jobs Created
After adding, you should see it listed in the **Current Cron Jobs** table:

```
Minute | Hour  | Day | Month | Weekday | Command
0      | 6,18  | *   | *     | *       | /usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php >> ...
```

### Check Log Files
After the first run (wait until 6 AM or trigger manually):

```bash
# SSH into your server or use File Manager in cPanel
cat /home/feyh9rczl7jz/public_html/carvendors-scraper/logs/cron.log

# Should show output like:
# CarSafari Scraper - 2025-12-04 06:00:01
# Found: 164
# Inserted: 12
# Updated: 65
# COMPLETED SUCCESSFULLY
```

### Trigger Manually (for Testing)
SSH into your server and run:
```bash
/usr/local/bin/php /home/feyh9rczl7jz/public_html/carvendors-scraper/scrape-carsafari.php
```

This confirms the script works before relying on cron.

---

## Troubleshooting

### "No Cron Jobs" After Adding
- Refresh the page (F5)
- Check if you clicked "Add New Cron Job" button
- Verify all fields filled (should have defaults: `*`)

### Cron Not Running
**Check:**
1. Is the path correct? (verify folder exists)
2. Is `scrape-carsafari.php` executable? (chmod 755)
3. Does `logs/` directory exist? (create if missing)
4. Check email for error messages

### Email Issues
- Check **Cron Email** field shows your email
- If disabled, try with `/dev/null` redirect first
- Then enable logging to see what happened

### "Command not found" in Log
- The path `/usr/local/bin/php` might be different
- Ask your hosting provider for correct PHP path
- Common alternatives: `/usr/bin/php`, `/usr/bin/php8`

---

## Production Checklist

Before going live:

- [ ] Script path verified and exists
- [ ] Test cron job runs successfully (manual trigger)
- [ ] Log file created and contains output
- [ ] Cron job appears in "Current Cron Jobs" list
- [ ] Database updated with new vehicles
- [ ] Images downloaded (check `/images/` folder)
- [ ] No error emails received
- [ ] Monitor for 24 hours to confirm regular execution

---

## Advanced: Custom Timing

### Every Monday at 3 AM
| Minute | Hour | Day | Month | Weekday | Command |
|--------|------|-----|-------|---------|---------|
| 0 | 3 | * | * | 1 | `/usr/local/bin/php ...scrape-carsafari.php >> logs/cron.log 2>&1` |

### 1st of every month at 9 AM
| Minute | Hour | Day | Month | Weekday | Command |
|--------|------|-----|-------|---------|---------|
| 0 | 9 | 1 | * | * | `/usr/local/bin/php ...scrape-carsafari.php >> logs/cron.log 2>&1` |

### Every 30 minutes (test mode)
| Minute | Hour | Day | Month | Weekday | Command |
|--------|------|-----|-------|---------|---------|
| */30 | * | * | * | * | `/usr/local/bin/php ...scrape-carsafari.php --no-details >> logs/cron.log 2>&1` |

---

## Need Help?

- **Hosting Support**: Ask your provider for the correct PHP path
- **Script Issues**: Check `logs/scraper_*.log` for detailed error messages
- **Cron Syntax**: See [crontab.guru](https://crontab.guru/) for timing help

---

**Version**: 1.0 | **Updated**: 2025-12-04 | **Status**: Ready for cPanel
