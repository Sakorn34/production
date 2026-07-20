@echo off
REM setup_line_tasks.bat — ติดตั้ง Windows Task Scheduler สำหรับ LINE notification
REM แก้ PHP_EXE ให้ตรง path บนเครื่องก่อนรัน (Run as Administrator)

set PHP_EXE=C:\AppServ\php8.2.12\php.exe
set CRON_DIR=D:\AppServ\www\production\finishgoogs_ma_update\cron

schtasks /Create /TN "Production_LINE_Schedule_Tick" /TR "\"%PHP_EXE%\" \"%CRON_DIR%\line_notify_scheduled.php\" --job=tick" /SC MINUTE /MO 1 /F
schtasks /Create /TN "Production_LINE_Notify_Worker" /TR "\"%PHP_EXE%\" \"%CRON_DIR%\line_notify_worker.php\"" /SC MINUTE /MO 2 /F
REM ด้านล่างเป็น optional ถ้าไม่ใช้ tick — เวลาตั้งจากหลังบ้านใช้ tick แทน
REM schtasks /Create /TN "Production_LINE_Daily_Summary" ...

echo Done. ตรวจ Task Scheduler หรือรัน: schtasks /Query /TN Production_LINE_Notify_Worker
pause
