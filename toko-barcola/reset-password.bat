@echo off
cd /d "%~dp0"
set "PHPEXE=php"
if exist "%~dp0..\..\php\php.exe" set "PHPEXE=%~dp0..\..\php\php.exe"
echo === Reset password TokoKu POS ===
set /p U=Username (contoh: admin): 
set /p P=Password baru: 
"%PHPEXE%" -r "$db=new PDO('mysql:host=127.0.0.1;dbname=toko','root',''); $s=$db->prepare('UPDATE app_users SET password_hash=?, aktif=1 WHERE username=?'); $s->execute([password_hash(getenv('P'),PASSWORD_DEFAULT),getenv('U')]); echo 'Baris diubah: '.$s->rowCount().PHP_EOL;"
set P=
pause
