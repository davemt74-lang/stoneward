<?php
declare(strict_types=1);
/** Section 91B6B: Admin LLM intent proposals; no authorization delegated to a model. */

/** The route table binds an LLM's label to an existing, authenticated Admin skill. */
function sf_admin_agent_llm_routes(): array {
    return [
        'ticketing_inventory_read'=>['view'=>'ticketing','execution'=>'authenticated_read'],
        'merch_inventory_read'=>['view'=>'products','execution'=>'authenticated_read'],
        'vinyl_inventory_read'=>['view'=>'pod','execution'=>'authenticated_read'],
        'membership_inventory_read'=>['view'=>'membership','execution'=>'authenticated_read'],
        'commerce_report'=>['view'=>'commerce-ops','execution'=>'authenticated_read'],
        'crm_report'=>['view'=>'crm','execution'=>'authenticated_read'],
        'journey_report'=>['view'=>'analytics','execution'=>'authenticated_read'],
        'ticketing_manager'=>['view'=>'ticketing','execution'=>'owner_initiated_workflow'],
        'products_manager'=>['view'=>'products','execution'=>'owner_initiated_workflow'],
        'membership_manager'=>['view'=>'membership','execution'=>'owner_initiated_workflow'],
        'fulfillment_manager'=>['view'=>'fulfillment','execution'=>'owner_initiated_workflow'],
        'pod_manager'=>['view'=>'pod','execution'=>'owner_initiated_workflow'],
        'payments_manager'=>['view'=>'payments','execution'=>'owner_initiated_workflow'],
        'catalog_manager'=>['view'=>'catalog','execution'=>'owner_initiated_workflow'],
        'release_manager'=>['view'=>'releases','execution'=>'owner_initiated_workflow'],
        'shows_manager'=>['view'=>'shows','execution'=>'owner_initiated_workflow'],
    ];
}

/** Never export original Admin prose, names, order IDs, addresses or history. */
function sf_admin_agent_llm_safe_words(string $message): string {
    $vocabulary=explode(' ',
        'open go navigate visit manage edit review inspect show list count how many what where which ' .
        'status available pending active draft published latest today upcoming current total number ' .
        'ticket tickets ticketing vip guest reservation reservations offer offers show shows live ' .
        'merch merchandise products product inventory stock low quantities prices vinyl record records ' .
        'pressing manufacturing pod handoff handoffs production orders order fulfillment shipping ' .
        'payments payment stripe refund refunds sales revenue membership members subscriptions ' .
        'catalog songs tracks releases albums artist fans crm audience campaigns marketing analytics ' .
        'website page dashboard');
    $allowed=array_fill_keys($vocabulary,true);
    preg_match_all('/[a-z]+/i',strtolower(substr($message,0,1200)),$matches);
    $tokens=[];
    foreach($matches[0] as $word){
        if(isset($allowed[$word]))$tokens[]=$word;
        if(count($tokens)>=40)break;
    }
    return implode(' ',$tokens);
}

function sf_admin_agent_llm_validate(string $response): ?array {
    $raw=trim($response);
    if(str_starts_with($raw,'```'))$raw=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$raw)??$raw;
    $parsed=json_decode($raw,true);
    if(!is_array($parsed)||!is_string($parsed['intent']??null))return null;
    $name=$parsed['intent'];$routes=sf_admin_agent_llm_routes();
    if(!isset($routes[$name]))return null;
    return ['type'=>$name,'view'=>$routes[$name]['view'],'requires_confirmation'=>false,
        'execution'=>$routes[$name]['execution']];
}

/** Exact edits, production evidence and private identifiers retain local policy. */
function sf_admin_agent_llm_eligible(string $message,array $fallback): bool {
    if(!empty($fallback['requires_confirmation']))return false;
    $type=(string)($fallback['type']??'');
    if(preg_match('/^(?:edit_|prepare_|production_|stem_|attach_|draft_|catalog_inventory_|release_inventory_|shows_inventory_|knowledge_inventory_|media_)/',$type))return false;
    if(preg_match('/(?:\bSF-[A-Z0-9-]{3,}\b|\b[A-Z0-9]{8,}-[A-Z0-9-]{8,}\b|@|https?:\/\/|\b(?:password|secret|token|api key|customer name|private)\b)/i',$message))return false;
    $safe=sf_admin_agent_llm_safe_words($message);
    // A lone "what" or "open" is not enough evidence for an LLM to
    // select a commerce tool; let the general conversation router handle it.
    return (bool)preg_match('/\b(?:ticket|tickets|ticketing|vip|reservation|reservations|merch|merchandise|product|products|inventory|stock|vinyl|record|records|pressing|pod|orders|order|fulfillment|payments|payment|stripe|refund|refunds|sales|revenue|membership|members|subscriptions|catalog|songs|tracks|releases|albums|fans|crm|audience|campaigns|analytics)\b/',$safe);
}

/** An LLM proposes a read/navigation intent; the Admin brain still applies the tool contract. */
function sf_admin_agent_llm_plan(string $message,array $fallback): ?array {
    if(!sf_admin_agent_llm_eligible($message,$fallback))return null;
    $safe=sf_admin_agent_llm_safe_words($message);
    $options=sf_admin_agent_llm_routes();
    $prompt='Select the best Admin intent for the supplied sanitized keywords. ' .
        'Queries asking for quantities, statuses, tickets, inventory, vinyl or memberships need a *_read intent. ' .
        'Navigation or editing requests must select the corresponding *_manager workspace. ' .
        'You cannot execute writes, disclose private records, send messages, collect payments or approve anything. ' .
        'Return ONLY JSON {"intent":"exact_allowed_intent"}. Allowed intents: '.implode(', ',array_keys($options));
    if(!function_exists('sf_ai_llm_candidates'))return null;
    try{$providers=sf_ai_llm_candidates('agent_brain');}catch(Throwable $e){return null;}
    foreach(array_slice($providers,0,2) as $provider){
        try{
            $result=$provider['provider']==='openai'
                ?sf_openai_call($provider,$prompt,[],$safe,120)
                :sf_anthropic_call($provider,$prompt,[],$safe,120);
            $decision=sf_admin_agent_llm_validate((string)($result['text']??''));
            if($decision!==null)return $decision;
        }catch(Throwable $e){/* No model response can bypass the deterministic fallback. */}
    }
    return null;
}

/** Only read-only, aggregate server facts leave their respective authoritative modules. */
function sf_admin_agent_commerce_read(string $intent): array {
    if($intent==='ticketing_inventory_read'){
        $s=sf_ticket_summary();
        return ['source'=>'ticketing-core','summary'=>$s,
            'brief'=>'Tickets: '.$s['offers'].' offers ('.$s['published'].' published), '.$s['reservations'].
                ' reservations, '.$s['reserved_qty'].' reserved tickets, '.$s['checked_in_qty'].
                ' checked in, and '.$s['vip_reservations'].' VIP reservations.'];
    }
    if($intent==='merch_inventory_read'){
        $s=sf_commerce_summary();
        return ['source'=>'commerce-core','summary'=>$s,
            'brief'=>'Merchandise: '.$s['products'].' products ('.$s['active'].' active), '.
                $s['low_stock'].' low-stock listings or variants, '.$s['units_sold'].' units sold. ' .
                'Recorded merchandise order-item subtotals: $'.number_format($s['gross_merch_cents']/100,2).
                ' (not verified payment revenue).'];
    }
    if($intent==='membership_inventory_read'){
        $s=sf_membership_summary();
        return ['source'=>'membership-core','summary'=>$s,
            'brief'=>'Memberships: '.$s['active_members'].' active members, '.
                $s['published_content'].' published member-content entries.'];
    }
    if($intent==='vinyl_inventory_read'){
        $products=sf_commerce_products(false);$vinylProducts=0;$active=0;
        foreach($products as $p){
            if(!preg_match('/\b(?:vinyl|records?|lp|7-inch|12-inch)\b/i',(string)($p['category']??'')))continue;
            $vinylProducts++;if(($p['status']??'')==='active')$active++;
        }
        $handoffs=function_exists('sf_admin_pod_handoffs')?sf_admin_pod_handoffs():[];
        $pending=0;
        foreach($handoffs as $h){
            if(!in_array(strtolower((string)($h['status']??'')),['completed','fulfilled','delivered','cancelled','canceled'],true))$pending++;
        }
        $s=['vinyl_listings'=>$vinylProducts,'active_vinyl_listings'=>$active,
            'pod_handoffs'=>count($handoffs),'open_pod_handoffs'=>$pending];
        return ['source'=>'commerce-products-and-pod-handoffs','summary'=>$s,
            'brief'=>'Vinyl/physical records: '.$vinylProducts.' classified product listings ('.$active.
                ' active); '.count($handoffs).' POD handoffs ('.$pending.
                ' not yet marked completed/cancelled). Review the POD workspace for exact job status.'];
    }
    throw new InvalidArgumentException('Unsupported Admin commerce read.');
}
