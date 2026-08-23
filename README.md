# 📊 SQLPrefmon

[![PHP Version](https://img.shields.io/badge/PHP-7.4%20%7C%208.0%20%7C%208.1%20%7C%208.2-blue.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-SQLite-green.svg)](https://www.sqlite.org/)
[![OS Support](https://img.shields.io/badge/OS-Windows%20%7C%20Linux-orange.svg)](#)
[![License](https://img.shields.io/badge/License-MIT-brightgreen.svg)](#)

**SQLPrefmon** is a lightweight, secure, and highly efficient Microsoft SQL Server performance monitoring and tuning advisory platform. It connects to multiple SQL Server instances, monitors server health and resource usage using Dynamic Management Views (DMVs), logs metrics into a local SQLite database, and automatically generates tuning advice (such as missing indexes, heavy queries, and database configurations).

---

## 📸 Screenshots

Here are the screenshots showcasing the SQLPrefmon sidebar menu and user interface pages:

### 1. Dashboard Overview
![Dashboard Summary](assets/screenshots/Screenshot%201.png)
*Real-time monitoring status, resource summary, and health checks of all registered SQL Server instances.*

### 2. Active Servers
![Active Servers](assets/screenshots/Screenshot%202.png)
*Detailed view of currently active SQL Server instances under monitoring, showing connection status and environment details.*

### 3. Tuning Recommendations
![Tuning Recommendations](assets/screenshots/Screenshot%203.png)
*Automated tuning advisor suggestions, showing indexing recommendations, server configuration adjustments, and database optimization tips.*

### 4. Historical Trends
![Historical Trends](assets/screenshots/Screenshot%204.png)
*Longitudinal graphs and charts plotting historical performance metrics like CPU, memory, PLE, and compilation rates over time.*

### 5. Query History
![Query History](assets/screenshots/Screenshot%205.png)
*Captured SQL statements with execution metrics (duration, CPU, reads/writes) and execution plan options.*

### 6. Blocking Log
![Blocking Log](assets/screenshots/Screenshot%206.png)
*Historical and real-time blocking chains tracking lock contention, blocking sessions, and blocked queries.*

### 7. Deadlocks Log
![Deadlocks Log](assets/screenshots/Screenshot%207.png)
*Deadlock events monitoring, documenting victim queries, graphs, and troubleshooting metrics.*

### 8. DB File Analysis
![DB File Analysis](assets/screenshots/Screenshot%208.png)
*In-depth disk space and latency metrics per database file (.mdf and .ldf), highlighting disk performance bottlenecks.*

### 9. Backup Monitoring
![Backup Monitoring](assets/screenshots/Screenshot%209.png)
*Audit logs and status alerts for database backups (Full, Differential, Transaction Log) ensuring disaster recovery readiness.*

### 10. Agent Job Status
![Agent Job Status](assets/screenshots/Screenshot%2010.png)
*Detailed status, execution history, and error details of SQL Server Agent jobs.*

### 11. Always On & Cluster
![Always On & Cluster](assets/screenshots/Screenshot%2011.png)
*Health, synchronization status, and failover state of Always On Availability Groups and failover cluster instances.*

### 12. Alert Center
![Alert Center](assets/screenshots/Screenshot%2012.png)
*Unified alert management dashboard showing critical thresholds, active alerts, and historical warning events.*

---

## ✨ Features

- **Multi-Instance Dashboard:** Monitor multiple SQL Server instances from a single unified control panel.
- **Automated Tuning Advisor:** Analyzes DMV statistics to suggest missing indexes, query parameterization, tempdb optimizations, and memory configurations.
- **Deep DMV Performance Diagnostics:**
  - **Memory:** Page Life Expectancy (PLE) trends, buffer pool usage, query memory grants.
  - **CPU:** SQL Compilation/Re-compilation rates, scheduler yield wait statistics.
  - **Disk Latency:** Average read/write latency metrics per database file.
  - **Locks & Blocking:** Real-time active blocking chains, deadlock statistics, and wait times.
  - **Wait Stats:** Ranks top bottlenecking wait types (e.g., `CXPACKET`, `PAGEIOLATCH_SH`, `LCK_M_X`).
- **Secure Credentials:** SQL Server passwords are encrypted using industry-standard AES-256 encryption via OpenSSL before database storage.
- **Low Footprint:** Built on PHP and SQLite—no external heavy monitoring agents or database engine installations required on the target databases.

---

## 🔮 Upcoming Features

The following features are planned for future updates:

1. **Deadlock Monitoring:** Real-time tracking and visualization of deadlock events, victim details, and contributing queries.
2. **Backup Monitoring:** Audit database backup history (Full, Differential, Transaction Log) and generate alerts for stale or missing backups.
3. **MDF and LDF Free Space Monitoring:** Proactive monitoring of database file growth, individual `.mdf` and `.ldf` file sizes, and warning alerts for low disk capacity.
4. **Email Notifications:** Automatic alert routing via SMTP configuration for critical server status and performance issues.
5. **Agent Job Failure Monitoring:** Detailed reporting of SQL Server Agent job statuses, failure alerts, and step-by-step error analysis.

---

## 🛠️ Prerequisites & Requirements

Ensure the monitoring server meets the following requirements:

### 1. PHP Engine & Extensions
- **PHP Version:** 7.4 or newer (8.0+ recommended)
- **Required Extensions:**
  - `pdo_sqlite` (for the local configuration and metrics repository)
  - `openssl` (for secure server credential encryption)
  - `pdo_odbc` (for connecting to Microsoft SQL Server)

### 2. SQL Server ODBC Drivers
- **Windows Server:** Install [Microsoft ODBC Driver for SQL Server](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server) (Driver 18 or 17).
- **Linux:** Install Microsoft ODBC Driver via package manager (e.g., `msodbcsql18` or `msodbcsql17`).

---

## 🚀 Installation & Initial Setup

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/your-username/SQLPrefmon.git
   cd SQLPrefmon
   ```

2. **Configure Database & Parameters:**
   Open [config/app.php](file:///c:/Users/sonum/Desktop/Antigravity/MSSQL_Prefmon/config/app.php) and adjust configurations such as the database path, log locations, security keys, and metric thresholds:
   ```php
   // Change secret key for AES-256 database password encryption
   define('APP_KEY', 'your-random-32-character-secret-key');

   // Adjust global alert thresholds if needed
   define('THRESHOLD_CPU_PCT', 85.0);
   define('THRESHOLD_PLE_SEC', 300);
   ```

3. **Initialize Database Tables:**
   Run the initialization script from the terminal to create the local SQLite database and populate tables:
   ```bash
   php engine/setup.php
   ```
   *This creates the database file in `data/sqlperf.db`.*

4. **Default Credentials:**
   The setup script automatically seeds a default administrator account:
   - **Username:** `admin`
   - **Password:** `Sumo@123`
   
   > [!IMPORTANT]
   > For security reasons, log in immediately and change this password under the User Profile settings.

5. **Start Web Server:**
   For development/testing, you can run PHP's built-in server:
   ```bash
   php -S localhost:8000
   ```
   Open `http://localhost:8000` in your browser. For production environments, configure Apache, Nginx, or IIS to serve the root directory.

---

## ⏱️ Configuring Monitoring Schedule

The script `engine/collect.php` runs metrics collection, queries DMVs on all active SQL servers, triggers tuning analyzers, and writes logs. To run monitoring continuously, you must schedule this script.

### 🛡️ Option A: Linux Configuration (Cron Job)

On Linux systems, use the `cron` daemon to schedule metric collection.

1. Open the crontab configuration for the user running the web server (e.g. `www-data` or a dedicated user):
   ```bash
   crontab -e
   ```

2. Add a cron entry to execute the collector script **every 5 minutes** (recommended interval):
   ```cron
   */5 * * * * /usr/bin/php /var/www/SQLPrefmon/engine/collect.php >> /var/www/SQLPrefmon/logs/collector.log 2>&1
   ```
   *Make sure to replace `/usr/bin/php` with your actual PHP binary path (find using `which php`) and `/var/www/SQLPrefmon` with the absolute path to your cloned project.*

3. Save and close the editor. Verify the cron task is listed:
   ```bash
   crontab -l
   ```

---

### 🛡️ Option B: Windows Server Configuration (Task Scheduler)

On Windows Server, configure a Scheduled Task to run the collector script.

#### Method 1: Automated setup using PowerShell (Recommended)
Run Windows PowerShell as **Administrator** and execute the following commands to create the task instantly:

```powershell
# Define paths (Adjust to match your system installation)
$phpPath = "C:\php\php.exe"
$scriptPath = "C:\inetpub\wwwroot\SQLPrefmon\engine\collect.php"
$taskName = "SQLPrefmon_Collector"

# Create action to launch php pointing to collect.php
$action = New-ScheduledTaskAction -Execute $phpPath -Argument "-f $scriptPath"

# Trigger: Run every 5 minutes, indefinitely
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)

# Task Settings
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances Parallel

# Register Scheduled Task under the System account for reliable background execution
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User "NT AUTHORITY\SYSTEM" -Force
```

---

#### Method 2: Manual setup using Task Scheduler GUI

1. Open **Task Scheduler** (`taskschd.msc`).
2. Click **Create Task...** in the Actions pane on the right.
3. In the **General** tab:
   - **Name:** `SQLPrefmon Metrics Collector`
   - **Security options:** Select **Run whether user is logged on or not** and check **Run with highest privileges**.
   - **Configure for:** Select your current Windows Server version.
4. In the **Triggers** tab:
   - Click **New...**
   - **Begin the task:** *On a schedule*
   - Under Advanced settings, check **Repeat task every:** select `5 minutes` and set **for a duration of:** `Indefinitely`.
   - Ensure **Enabled** is checked, then click **OK**.
5. In the **Actions** tab:
   - Click **New...**
   - **Action:** *Start a program*
   - **Program/script:** Enter path to your PHP executable (e.g., `C:\php\php.exe`).
   - **Add arguments (optional):** Enter `-f "C:\path\to\SQLPrefmon\engine\collect.php"`.
   - **Start in (optional):** Enter the folder path containing php (e.g., `C:\php`).
   - Click **OK**.
6. In the **Settings** tab:
   - Check **Run task as soon as possible after a scheduled start is missed**.
   - Check **If the running task does not end when requested, force it to stop**.
7. Click **OK** and enter administrative credentials if prompted.

---

## 🪵 Verification & Logs

- You can monitor execution by reading the log files generated by the scheduler:
  - **System Logs:** `logs/collector.log` contains detailed records of metrics collection runs and errors connecting to target servers.
- Database status can also be viewed via the **Settings/Servers** page on the web dashboard to see the latest collection timestamp.

## 📄 License
This project is licensed under the MIT License.
