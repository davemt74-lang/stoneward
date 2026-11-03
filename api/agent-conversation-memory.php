<?php
declare(strict_types=1);

/**
 * Section 91B17A — private, bounded continuity between Customer Agent chats.
 * A prior conversation is not a saved preference or proof that an action ran.
 * All database reads use the authenticated owner, and personalization is the
 * existing consent switch. Nothing is automatically promoted to durable memory.
 */
function sf_agent_memory_words(string $value): array {
    $stop=array_fill_keys(explode(' ','a about again all and are ask asked back been before can chat chats conversation conversations did discuss discussed do does earlier find for from have how into its last like me more most much my next our past please previous remember said say search she should show some stonefellow talk talked talking that the their them there these they this time told was were what when where which who why will with would you your'),true);
    $words=preg_split('/[^a-z0-9]+/',strtolower($value))?:[];
    $terms=[];
    foreach($words as $word){
        if(strlen($word)<3||strlen($word)>30||isset($stop[$word]))continue;
        $terms[$word]=true;
        if(count($terms)>=14)break;
    }
    return array_keys($terms);
}

function sf_agent_memory_contains_secret(string $value): bool {
    return preg_match('/\b(?:password|passcode|api[ -]?key|secret[ -]?key|private[ -]?key|access[ -]?token|refresh[ -]?token|cvv|card number|credit card|social security number)\b/i',$value)===1;
}

/** Pure ranking helper: its input is already owner-filtered, previous user text. */
function sf_agent_memory_rank_rows(array $rows,string $query,int $limit=4): array {
    $limit=max(1,min(6,$limit));
    $terms=sf_agent_memory_words($query);
    $broad=preg_match('/\b(?:last time|last chat|previous conversation|earlier conversation|what did (?:we|i) (?:discuss|say|talk)|remember what (?:we|i) (?:discussed|said|talked))\b/i',$query)===1;
    $matches=[];
    foreach(array_slice($rows,0,180) as $rank=>$row){
        if(!is_array($row)||($row['role']??'user')!=='user')continue;
        $body=trim((string)($row['content']??''));
        // The current lookup question is already in the chat ledger. Never
        // return that question as a purported recollection of the past.
        if($body===''||strlen($body)<10||strcasecmp($body,trim($query))===0||sf_agent_memory_contains_secret($body))continue;
        $words=array_fill_keys(sf_agent_memory_words($body),true);
        $overlap=0;
        foreach($terms as $term)if(isset($words[$term]))$overlap++;
        // A broad "what did we discuss last time" query can use recency.
        // Otherwise a single generic word cannot import an unrelated chat.
        if($overlap===0&&!($broad&&!$terms))continue;
        if($overlap<2&&count($terms)>2&&!$broad)continue;
        $matches[]=['score'=>$overlap*100-max(0,$rank)*.15,'rank'=>$rank,'row'=>$row];
    }
    usort($matches,static fn($a,$b)=>$b['score']<=>$a['score']);
    $out=[];
    foreach(array_slice($matches,0,$limit) as $entry){
        $row=$entry['row'];
        $content=preg_replace('/\s+/u',' ',trim((string)$row['content']))??trim((string)$row['content']);
        $out[]=['conversation_id'=>(string)($row['conversation_id']??''),'date'=>substr((string)($row['created_at']??''),0,16),
            'excerpt'=>function_exists('mb_substr')?mb_substr($content,0,320):substr($content,0,320)];
    }
    return $out;
}

function sf_agent_cross_thread_recall(int $userId,string $currentConversationId,string $query,int $limit=4): array {
    if($userId<1||!function_exists('sf_privacy_personalization_enabled')||!sf_privacy_personalization_enabled($userId))return [];
    $q=sf_db()->prepare("SELECT conversation_id,role,content,created_at FROM agent_messages WHERE user_id=? AND conversation_id<>? AND role='user' ORDER BY id DESC LIMIT 180");
    $q->execute([$userId,$currentConversationId]);
    return sf_agent_memory_rank_rows($q->fetchAll(PDO::FETCH_ASSOC),$query,$limit);
}

/** Historical messages are searched only on the server and shown to their owner.
 * This route never adds private earlier chat excerpts to an external LLM call.
 */
function sf_agent_customer_memory_query_requested(string $message): bool {
    $q=trim(preg_replace('/^(?:(?:hey|hi|okay|ok)\s+)?stone\s*-?\s*fellow[,.:!?\s]*/iu','',trim($message))??$message);
    return preg_match('/^(?:please\s+)?(?:what did (?:we|i) (?:talk about|discuss|say)(?:\s+(?:about|last time))?|what (?:were|was) (?:we|i) (?:talking|saying) about|remember what (?:we|i) (?:discussed|said|talked about)|(?:search|find|look up) (?:in )?(?:my|our) (?:old |past |previous )?(?:chats|conversations)(?:\s+for)?|what did we talk about last time)\b/i',$q)===1;
}

function sf_agent_customer_memory_query_response(int $userId,string $conversationId,string $message): string {
    if(!function_exists('sf_privacy_personalization_enabled')||!sf_privacy_personalization_enabled($userId))
        return 'Previous-chat search is off. Enable Personalization in Account → Privacy if you want to search your private conversations.';
    // Include earlier turns of this conversation as well as other threads.
    // The exact current lookup is excluded by sf_agent_memory_rank_rows.
    try{$rows=sf_agent_cross_thread_recall($userId,'',$message,4);}
    catch(Throwable $ignored){return 'Your earlier conversations could not be searched right now. Your current chat is still available.';}
    if(!$rows)return 'I could not find a relevant earlier user statement in your recent private conversations. You can search the last 180 messages from My Agent Brain.';
    $parts=[];
    foreach($rows as $row)$parts[]='['.($row['date']?:'Earlier chat').'] You said: “'.(string)$row['excerpt'].'”';
    return 'Here is what I found in your earlier chats (your words, not verified actions): '.implode(' | ',$parts).' You can open the conversation in My Agent Brain.';
}

/** No fuzzy deletion: a voice request must identify an exact saved label. */
function sf_agent_customer_memory_command(string $message): ?array {
    $text=trim(preg_replace('/^(?:(?:hey|hi|okay|ok)\s+)?stone\s*-?\s*fellow[,.:!?\s]*/iu','',trim($message))??$message);
    if(preg_match('/^(?:please\s+)?(?:what do you remember about me|what have you remembered about me|(?:show|list|read) (?:me )?(?:my )?(?:saved )?(?:agent )?memories|what is in my agent memory)[.!?]*$/iu',$text))return ['type'=>'list'];
    if(preg_match('/^(?:please\s+)?(?:forget|remove from (?:my )?(?:agent )?memory)\s+(?:the\s+)?(?:memory\s+(?:labeled|called)\s+)?(.+?)[.!?]*$/iu',$text,$m)){
        $key=strtolower(trim((string)$m[1]," \t\n\r\0\x0B\"'“”"));
        return ['type'=>'forget','label'=>$key];
    }
    if(preg_match('/^(?:please\s+)?(?:remember|save (?:this|that) (?:to|in) (?:my )?(?:agent )?memory)\s+(?:that\s+)?(.+?)\s*$/isu',$text,$m)){
        $value=trim((string)$m[1]);
        if(preg_match('/^([^:]{2,55}):\s*(.+)$/us',$value,$labelMatch)){
            $label=strtolower(trim((string)$labelMatch[1]));$value=trim((string)$labelMatch[2]);
        }else{
            $short=function_exists('mb_substr')?mb_substr($value,0,52):substr($value,0,52);
            $label=strtolower(trim(preg_replace('/[^a-z0-9_ -]+/i','',strtolower($short))??''));
        }
        if(strlen($label)<2)$label='memory_'.substr(hash('sha256',$value),0,12);
        return ['type'=>'remember','label'=>$label,'text'=>$value];
    }
    return null;
}

function sf_agent_customer_memory_execute(int $userId,array $command): string {
    if(!function_exists('sf_privacy_personalization_enabled')||!sf_privacy_personalization_enabled($userId))
        return 'Agent memory is off. Enable Personalization in Account → Privacy to use saved memories and previous-chat recall.';
    $type=(string)($command['type']??'');
    try{
        if($type==='remember'){
            if(sf_agent_memory_contains_secret((string)($command['text']??'')))
                return 'For your privacy, I cannot save passwords, API keys, payment details, or other secrets in Agent memory.';
            $entry=sf_agent_customer_memory_save($userId,(string)($command['label']??''),(string)($command['text']??''));
            return 'Saved to your private Agent Brain: '.(string)$entry['memory_text'];
        }
        if($type==='forget'){
            $label=(string)($command['label']??'');
            if($label===''||strlen($label)>60)return 'Please name the exact saved memory label to forget.';
            $done=sf_agent_customer_memory_forget($userId,$label);
            return $done?'Forgot the saved memory labeled “'.$label.'”.':'No saved memory has that exact label. Open My Agent Brain to review your memory labels.';
        }
        if($type==='list'){
            $rows=sf_agent_customer_memory_list($userId);
            if(!$rows)return 'There are no explicitly saved memories yet. You can say “remember that I prefer acoustic songs” to add one.';
            $parts=[];
            foreach(array_slice($rows,0,12) as $row)$parts[]=(string)$row['memory_key'].': '.(string)$row['memory_text'];
            return 'Here are your saved Agent memories: '.implode('; ',$parts).(count($rows)>12?'; and '.(count($rows)-12).' more in My Agent Brain.':'.');
        }
    }catch(InvalidArgumentException $e){return 'I could not save that memory. Give it a short label and a note under 1,200 characters.';}
    catch(RuntimeException $e){return 'I could not change your saved memories. Please check your privacy setting or memory limit in My Agent Brain.';}
    return 'That memory command was not recognized.';
}
