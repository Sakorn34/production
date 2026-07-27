@echo off

REM setup_line_tasks.bat — ติดตั้ง Windows Task Scheduler สำหรับ LINE notification (dev)

REM แก้ PHP_EXE ให้ตรง path บนเครื่องก่อนรัน (Run as Administrator)

REM เวลาส่งจริงตั้งที่ Plesk (plesk_line_job_*.php) — บน Windows ใช้ worker สำรอง outbox



set PHP_EXE=D:\AppServ\php7\php.exe

if not exist "%PHP_EXE%" set PHP_EXE=C:\AppServ\php8.2.12\php.exe

set CRON_DIR=D:\AppServ\www\production\finishgoogs_ma_update\cron



schtasks /Create /TN "Production_LINE_Notify_Worker" /TR "\"%PHP_EXE%\" \"%CRON_DIR%\line_notify_worker.php\"" /SC MINUTE /MO 2 /F



echo Done. ตรวจ Task Scheduler หรือรัน: schtasks /Query /TN Production_LINE_Notify_Worker

pause

