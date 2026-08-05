<?php
declare(strict_types=1);
require_once __DIR__.'/../config/env.php';

function backupDirectory(): string {
    $projectRoot=dirname(__DIR__);$configured=trim((string)envValue('LMS_BACKUP_DIR',''));
    $defaultBase=DIRECTORY_SEPARATOR==='\\'?dirname($projectRoot,2):dirname($projectRoot);
    $path=$configured!==''?$configured:$defaultBase.DIRECTORY_SEPARATOR.(DIRECTORY_SEPARATOR==='\\'?'LMS_Backups':'lms_backups');
    if(!is_dir($path)&&!mkdir($path,0750,true)&&!is_dir($path))throw new RuntimeException('Không thể tạo thư mục sao lưu: '.$path);
    $real=realpath($path);if(!$real)throw new RuntimeException('Không thể xác định thư mục sao lưu.');
    if(str_starts_with($real.DIRECTORY_SEPARATOR,realpath($projectRoot).DIRECTORY_SEPARATOR))throw new RuntimeException('Thư mục sao lưu phải nằm ngoài thư mục website.');
    return $real;
}
function sqlLiteral(PDO $pdo,mixed $value): string { return $value===null?'NULL':$pdo->quote((string)$value); }
function createDatabaseBackup(PDO $pdo): array {
    $dir=backupDirectory();$stamp=date('Ymd_His');$base='lms_database_'.$stamp.'.sql';$sqlPath=$dir.DIRECTORY_SEPARATOR.$base;
    $handle=fopen($sqlPath,'wb');if(!$handle)throw new RuntimeException('Không thể tạo file sao lưu.');
    fwrite($handle,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'") as $row)$tables[]=(string)array_values($row)[0];
    foreach($tables as $table){$create=$pdo->query('SHOW CREATE TABLE `'.str_replace('`','``',$table).'`')->fetch();$createSql=(string)array_values($create)[1];fwrite($handle,"DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n");$query=$pdo->query('SELECT * FROM `'.str_replace('`','``',$table).'`');while($row=$query->fetch(PDO::FETCH_ASSOC)){$columns=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($row));$values=array_map(fn($v)=>sqlLiteral($pdo,$v),array_values($row));fwrite($handle,'INSERT INTO `'.$table.'` ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");}fwrite($handle,"\n");}
    fwrite($handle,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($handle);$finalPath=$sqlPath;
    if(function_exists('gzopen')){$gzPath=$sqlPath.'.gz';$in=fopen($sqlPath,'rb');$out=gzopen($gzPath,'wb9');while(!feof($in))gzwrite($out,(string)fread($in,1048576));fclose($in);gzclose($out);unlink($sqlPath);$finalPath=$gzPath;}@chmod($finalPath,0640);
    $assets=null;$uploads=dirname(__DIR__).DIRECTORY_SEPARATOR.'uploads';
    if(class_exists('ZipArchive')&&is_dir($uploads)){$zipPath=$dir.DIRECTORY_SEPARATOR.'lms_local_files_'.$stamp.'.zip';$zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)===true){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads,FilesystemIterator::SKIP_DOTS));foreach($iterator as $file){$full=$file->getPathname();$relative=str_replace('\\','/',substr($full,strlen($uploads)+1));if(str_starts_with($relative,'temp_ai/'))continue;if($file->isFile())$zip->addFile($full,'uploads/'.$relative);}$zip->close();if(filesize($zipPath)>0)$assets=$zipPath;else @unlink($zipPath);}}
    rotateBackups($dir,5);return ['database'=>$finalPath,'assets'=>$assets,'created_at'=>date(DATE_ATOM)];
}
function rotateBackups(string $dir,int $keep): void {foreach(['lms_database_*','lms_local_files_*'] as $pattern){$files=glob($dir.DIRECTORY_SEPARATOR.$pattern)?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));foreach(array_slice($files,$keep) as $file)if(is_file($file))unlink($file);}}
function listBackups(): array {$dir=backupDirectory();$files=glob($dir.DIRECTORY_SEPARATOR.'lms_database_*.sql*')?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));return array_map(fn($f)=>['name'=>basename($f),'path'=>$f,'size'=>filesize($f),'created_at'=>filemtime($f)],$files);}
function listLocalFileBackups(): array {$dir=backupDirectory();$files=glob($dir.DIRECTORY_SEPARATOR.'lms_local_files_*.zip')?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));return array_map(fn($f)=>['name'=>basename($f),'path'=>$f,'size'=>filesize($f),'created_at'=>filemtime($f)],$files);}
function safeBackupPath(string $name): ?string {$name=basename($name);if(!preg_match('/^lms_(database|local_files)_\d{8}_\d{6}\.(sql|sql\.gz|zip)$/',$name))return null;$path=backupDirectory().DIRECTORY_SEPARATOR.$name;return is_file($path)?$path:null;}
function restoreDatabaseBackup(PDO $pdo,string $path): void {$sql=str_ends_with($path,'.gz')?gzdecode((string)file_get_contents($path)):file_get_contents($path);if(!is_string($sql)||$sql==='')throw new RuntimeException('File sao lưu trống hoặc bị lỗi.');$pdo->exec($sql);}
