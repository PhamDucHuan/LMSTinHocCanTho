<?php
declare(strict_types=1);
require_once __DIR__.'/drive_helper.php';
require_once __DIR__.'/../vendor/autoload.php';
use Google\Service\Drive as Google_Service_Drive;
function collectDriveReferences(PDO $pdo): array {
    $refs=[];$add=static function(?string $id,string $source,string $name='')use(&$refs):void{if(!$id)return;
    $refs[$id]??=['drive_id'=>$id,'source'=>[],'name'=>$name];
    $refs[$id]['source'][]=$source;
    if($name!==''&&$refs[$id]['name']==='')$refs[$id]['name']=$name;
    };
    foreach($pdo->query('SELECT id,prompt_file_drive_id,prompt_file_name,solution_file_drive_id,solution_file_name,attachments,module_settings FROM assignments') as $row){$add($row['prompt_file_drive_id']??null,'assignment_prompt#'.$row['id'],(string)($row['prompt_file_name']??''));
    $add($row['solution_file_drive_id']??null,'assignment_solution#'.$row['id'],(string)($row['solution_file_name']??''));foreach(['attachments','module_settings'] as $field){$data=json_decode((string)($row[$field]??''),true);if(!is_array($data))continue;
    $walk=function($value)use(&$walk,$add,$row,$field){if(!is_array($value))return;
    if(isset($value['drive_id']))$add((string)$value['drive_id'],$field.'#'.$row['id'],(string)($value['name']??''));if(isset($value['solution_drive_id']))$add((string)$value['solution_drive_id'],$field.'#'.$row['id']);
    foreach($value as $child)if(is_array($child))$walk($child);};$walk($data);}}
    foreach($pdo->query('SELECT id,file_drive_id,file_name,submitted_files FROM submissions') as $row){$add($row['file_drive_id']??null,'submission#'.$row['id'],(string)($row['file_name']??''));
    $data=json_decode((string)($row['submitted_files']??''),true);if(is_array($data))foreach($data as $file)$add(is_array($file)?($file['drive_id']??null):null,'submission_files#'.$row['id'],is_array($file)?(string)($file['name']??''):'');}
    return array_values($refs);
}
function verifyDriveReferences(array $references): array {$service=null;
foreach($references as &$ref){if(str_starts_with($ref['drive_id'],'local_')){$ref['status']=resolveLocalDrivePath($ref['drive_id'])?'local_ok':'local_missing';
continue;}try{$service??=new Google_Service_Drive(getDriveClient());$file=$service->files->get($ref['drive_id'],['fields'=>'id,name,trashed']);
$ref['status']=$file->getTrashed()?'trashed':'ok';
$ref['drive_name']=$file->getName();
}catch(Throwable $error){$ref['status']='missing_or_denied';
$ref['error']=$error->getMessage();}}unset($ref);return $references;}
