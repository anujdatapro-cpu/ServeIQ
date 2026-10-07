@echo off
setlocal
set ERRORS=0
for /r "D:\sem5\WT\wt cp" %%f in (*.php) do (
    "D:\xampp\php\php.exe" -l "%%f" 2>&1 | findstr /c:"Parse error" /c:"Fatal error" > nul
    if not errorlevel 1 (
        echo SYNTAX ERROR: %%f
        set ERRORS=1
    )
)
if %ERRORS%==0 (
    echo ALL PHP FILES: NO SYNTAX ERRORS
) else (
    echo SYNTAX ERRORS FOUND
)
endlocal
