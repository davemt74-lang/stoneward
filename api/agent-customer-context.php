<?php
declare(strict_types=1);
/**
 * Section 47D: bounded, consent-gated positive music preference signals.
 * Private memory text never enters tool manifests or public catalog responses.
 */
function sf_agent_customer_preference_terms_from_rows(array $memories): array {
    $terms=[];
    $stop=['about'=>true,'really'=>true,'music'=>true,'songs'=>true,'song'=>true,'tracks'=>true,
        'the'=>true,'and'=>true,'that'=>true,'with'=>true,'these'=>true,'those'=>true,
        'like'=>true,'love'=>true,'enjoy'=>true,'prefer'=>true,'favorite'=>true,
        'favourite'=>true,'sounds'=>true,'sound'=>true,'style'=>true,'genre'=>true,
        'mood'=>true,'because'=>true,'recordings'=>true,'recording'=>true,'more'=>true,
        'when'=>true,'from'=>true,'something'=>true,'want'=>true,'quietly'=>true];
    foreach(array_slice($memories,0,25) as $row){
        if(!is_array($row))continue;
        $label=strtolower(substr((string)($row['memory_key']??''),0,60));
        $value=strtolower(substr((string)($row['memory_text']??''),0,1200));
        if($value==='')continue;
        // Avoid interpreting dislikes and negated instructions as positive tastes.
        if(preg_match('/\\b(?:don.t|doesn.t|not|never|avoid|dislike|hate|without)\\b/i',$value))continue;
        if(!preg_match('/\\b(?:like|love|enjoy|prefer|favorite|favourite)\\b/i',$value)
            && !preg_match('/\\b(?:music|mood|style|genre|sound|listening)\\b/i',$label))continue;
        foreach(preg_split('/[^a-z0-9]+/',$value)?:[] as $term){
            if(strlen($term)<4||strlen($term)>24||isset($stop[$term])||!preg_match('/^[a-z]+$/D',$term))continue;
            $terms[$term]=true;
            if(count($terms)>=32)break 2;
        }
    }
    return array_keys($terms);
}

function sf_agent_customer_preference_terms(int $userId): array {
    if($userId<1 || !function_exists('sf_privacy_personalization_enabled')
        || !sf_privacy_personalization_enabled($userId)
        || !function_exists('sf_agent_customer_memory_list'))return [];
    try{return sf_agent_customer_preference_terms_from_rows(sf_agent_customer_memory_list($userId));}
    catch(Throwable $e){return [];} // Revert to published public discovery if memory store is unavailable.
}

/** Score matching *published candidate metadata only*, never private IDs. */
function sf_agent_customer_track_affinity_score(array $track,array $terms): int {
    if(!$terms)return 0;
    $terms=array_fill_keys(array_slice(array_map('strtolower',$terms),0,32),true);
    $score=0;
    foreach(['mood'=>4,'themes'=>3] as $field=>$weight){
        foreach((array)($track[$field]??[]) as $tag){
            foreach(preg_split('/[^a-z0-9]+/',strtolower((string)$tag))?:[] as $word){
                if(isset($terms[$word]))$score+=$weight;
            }
        }
    }
    return min(12,$score);
}
