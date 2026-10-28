<?php
declare(strict_types=1);
/** Section 91B3A: exact-reviewed edits to Knowledge Base notes only. */
function sf_agent_knowledge_parse_note(string $message):?array {
    $message=trim($message);
    if(!preg_match('/\A(?:please\s+)?(?:set|update|change)\s+(?:the\s+)?(?:note|description)\s+(?:for|on)\s+(?:knowledge\s+)?(?:file|document)\s+"([^"\r\n]{1,180})"\s+to\s+(.{1,500})\z/isu',$message,$m))return null;
    $name=trim($m[1]);$value=trim($m[2]);
    if($name===''||$value==='')return null;
    return ['name'=>$name,'value'=>$value];
}
function sf_agent_knowledge_revision(array $file):string {
    return hash('sha256',json_encode([
        'id'=>(string)($file['id']??''),'notes'=>(string)($file['notes']??''),
        'updated_at'=>(string)($file['updated_at']??''),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
/** Pure normalization can be exercised against fixture rows without touching disk. */
function sf_agent_knowledge_normalize_note(array $raw,array $index,string $indexHash):array {
    $allowed=['file_id','file_name','field','previous','value','expected_revision','expected_index_hash'];
    if(array_diff(array_keys($raw),$allowed))throw new InvalidArgumentException('Unsupported knowledge action fields.');
    if(($raw['field']??'')!=='notes')throw new InvalidArgumentException('Only internal document notes may be changed.');
    $id=(string)($raw['file_id']??'');
    $value=trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags((string)($raw['value']??'')))??'');
    if($id===''||strlen($id)>100||$value===''||strlen($value)>500)
        throw new InvalidArgumentException('An exact document and a 1–500 byte description are required.');
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($raw['expected_index_hash']??''))
        ||!hash_equals((string)$raw['expected_index_hash'],$indexHash))
        throw new RuntimeException('The Knowledge Base changed; prepare a fresh review.');
    $matches=[];
    foreach((array)($index['files']??[]) as $i=>$file)if(is_array($file)&&(string)($file['id']??'')===$id)$matches[$i]=$file;
    if(count($matches)!==1)throw new InvalidArgumentException('The exact Knowledge Base file is missing or ambiguous.');
    $file=reset($matches);$revision=sf_agent_knowledge_revision($file);
    // Notes on public_agent files are searchable and can appear in the public
    // Agent's context. This narrow operation is private-metadata-only.
    if(!empty($file['public_agent']))
        throw new InvalidArgumentException('This file is enabled for the public Agent; edit it manually in Knowledge Base.');
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($raw['expected_revision']??''))
        ||!hash_equals((string)$raw['expected_revision'],$revision))
        throw new RuntimeException('The document changed; prepare a fresh review.');
    return ['file_id'=>$id,'file_name'=>(string)($file['name']??''),'field'=>'notes',
        'previous'=>(string)($file['notes']??''),'value'=>$value,
        'expected_revision'=>$revision,'expected_index_hash'=>$indexHash];
}
function sf_agent_knowledge_snapshot():array {
    require_once __DIR__.'/../admin/api/bootstrap.php';
    $raw=is_file(SF_ADMIN_KNOWLEDGE_INDEX)?file_get_contents(SF_ADMIN_KNOWLEDGE_INDEX):'';
    if(!is_string($raw))throw new RuntimeException('Knowledge Base cannot be inspected.');
    return ['index'=>sf_admin_knowledge_index(),'hash'=>hash('sha256',$raw)];
}
function sf_agent_action_normalize_knowledge_note(array $raw):array {
    $snapshot=sf_agent_knowledge_snapshot();
    return sf_agent_knowledge_normalize_note($raw,$snapshot['index'],$snapshot['hash']);
}
function sf_agent_knowledge_draft_from_chat(string $message):?array {
    $request=sf_agent_knowledge_parse_note($message);
    if($request===null)return null;
    $snapshot=sf_agent_knowledge_snapshot();$matches=[];
    foreach((array)($snapshot['index']['files']??[]) as $file){
        if(!is_array($file))continue;
        if(strcasecmp(trim((string)($file['name']??'')),$request['name'])===0)$matches[]=$file;
    }
    if(count($matches)!==1)throw new InvalidArgumentException('Use the exact unique Knowledge Base filename in quotes.');
    $file=$matches[0];
    return sf_agent_knowledge_normalize_note([
        'file_id'=>(string)$file['id'],'field'=>'notes','value'=>$request['value'],
        'expected_revision'=>sf_agent_knowledge_revision($file),
        'expected_index_hash'=>$snapshot['hash'],
    ],$snapshot['index'],$snapshot['hash']);
}
/** Apply only the approved notes field to the exact source row. */
function sf_agent_knowledge_apply_note(array $draft,array $index):array {
    $changed=0;
    foreach($index['files'] as &$file){
        if(!is_array($file)||(string)($file['id']??'')!==$draft['file_id'])continue;
        if(!hash_equals($draft['expected_revision'],sf_agent_knowledge_revision($file)))
            throw new RuntimeException('Document revision changed.');
        $file['notes']=$draft['value'];$file['updated_at']=gmdate('c');$changed++;
    }
    unset($file);
    if($changed!==1)throw new RuntimeException('The target document is not unique.');
    return $index;
}
function sf_agent_knowledge_save_approved_note(array $payload):array {
    $snapshot=sf_agent_knowledge_snapshot();
    $draft=sf_agent_knowledge_normalize_note($payload,$snapshot['index'],$snapshot['hash']);
    $updated=sf_agent_knowledge_apply_note($draft,$snapshot['index']);
    // The existing Admin file writer checks the snapshot while holding the
    // shared knowledge-index writer lock. A concurrent edit fails closed.
    sf_admin_write_knowledge($updated,$draft['expected_index_hash']);
    $verified=sf_admin_knowledge_index();$matching=array_values(array_filter((array)$verified['files'],
        static fn($file)=>is_array($file)&&(string)($file['id']??'')===$draft['file_id']));
    if(count($matching)!==1||(string)($matching[0]['notes']??'')!==$draft['value'])
        throw new RuntimeException('Knowledge note save could not be verified.');
    return ['file_id'=>$draft['file_id'],'field'=>'notes','status'=>'metadata_saved'];
}
