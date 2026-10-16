<?php
declare(strict_types=1);
/**
 * 87C4 — Read-only campaign strategy for the existing Stonefellow
 * Campaign Builder. Eligibility is always rechecked before an actual send
 * by sf_campaign_send_recipient_allowed/sf_automation_outreach_allowed.
 * No fan targeting is inferred from private listening behavior.
 */
function sf_fan_conversion_eligible_newsletter_count():int {
    sf_crm_ensure_schema();
    return (int)sf_db()->query(
        'SELECT COUNT(*) FROM fan_contacts WHERE marketing_opt_in=1 AND unsubscribed_at IS NULL'
    )->fetchColumn();
}
function sf_fan_conversion_campaign_strategy(array $diagnostics,int $eligible):array {
    $eligible=max(0,min(1000000000,$eligible));
    $allowed=['sample_limited','repeat_interest','skip_events',
        'save_discovery','purchase_path','vinyl_path','baseline'];
    $codes=[];
    foreach(array_slice((array)($diagnostics['opportunities']??[]),0,20) as $item){
        if(!is_array($item))continue;
        $code=(string)($item['code']??'');
        if(in_array($code,$allowed,true))$codes[$code]=true;
    }
    $ideas=[];
    $add=static function(string $code,string $channel,string $title,string $reason,
        string $action,bool $newsletter=false)use(&$ideas,$eligible):void {
        if($newsletter&&$eligible<1)return;
        $ideas[]=[
            'code'=>$code,'channel'=>$channel,'title'=>$title,
            'reason'=>$reason,'next_step'=>$action,
            'audience'=>$newsletter?'existing_opted_in_newsletter_only':'public_onsite',
            'eligible_contact_count'=>$newsletter?$eligible:null,
            'template'=>$newsletter?'newsletter':null,
            'review_required'=>true,'send_authorized'=>false,'executed'=>false,
        ];
    };
    if(isset($codes['sample_limited'])) {
        $add('collect_evidence','onsite','Gather useful engagement evidence',
            'The listening sample is too small to justify a targeted promotion.',
            'Review Admin Analytics before drafting a campaign.');
    } else {
        if(isset($codes['repeat_interest'])) {
            $add('public_music_feature','onsite','Feature public songs fans replay',
                'Repeat starts suggest reviewing music discovery placement.',
                'Review the public catalog presentation.');
            $add('newsletter_music_review','newsletter_review','Review a music newsletter draft',
                'Consider featuring currently public Stonefellow music to an already opted-in audience.',
                'Prepare an unsent newsletter in the existing Campaign Builder. Review its audience and copy.',true);
        }
        if(isset($codes['save_discovery']))$add('save_action','onsite','Clarify Save and Library actions',
            'Listening activity is not translating into many favorite events in this window.',
            'Review the player and track-page Save controls.');
        if(isset($codes['purchase_path']))$add('cart_guidance','onsite','Review the track-to-cart journey',
            'The observed listening count is separate from paid-order totals.',
            'Inspect purchase discovery, canonical cart, and licensed checkout.');
        if(isset($codes['vinyl_path'])) {
            $add('vinyl_handoff','onsite','Review the vinyl Builder handoff',
                'Saved builds and paid custom-media orders are independent measures.',
                'Check licensing, builder review and checkout usability.');
            $add('newsletter_vinyl_review','newsletter_review','Review a vinyl newsletter draft',
                'An existing opted-in audience may appreciate public information about custom vinyl.',
                'Prepare an unsent newsletter, then verify consent, public offerings and terms before publication.',true);
        }
        if(isset($codes['skip_events']))$add('playback_quality','onsite','Review preview quality',
            'Skip-event counts warrant a playback and content check, not an automatic outreach campaign.',
            'Inspect player reliability and preview metadata.');
    }
    if(!$ideas)$add('baseline','onsite','Keep observing fan journeys',
        'There is no supported campaign intervention from this window.',
        'Review new activity before preparing offers.');
    return [
        'eligible_opted_in_contacts'=>$eligible,
        'ideas'=>array_slice($ideas,0,8),
        'reliable_audience'=>'Opted-in contacts are counted from current CRM preferences; unsubscribed contacts excluded.',
        'sending_control'=>'All email sends use existing campaign/automation live consent rechecks, review gates and unsubscribe controls.',
        'privacy'=>'No private fan segment, browsing history, contact details or inferred newsletter subscription.',
        'any_send_executed'=>false,'campaign_created'=>false,
    ];
}

/** Narrow allowlist is safe to pass to the existing Admin Agent LLM. */
function sf_fan_conversion_agent_brief(array $strategy):array {
    $eligible=$strategy['eligible_opted_in_contacts']??null;
    if(!is_int($eligible)||$eligible<0||$eligible>1000000000)$eligible=0;
    $allowed=['collect_evidence','public_music_feature','newsletter_music_review',
        'save_action','cart_guidance','vinyl_handoff','newsletter_vinyl_review',
        'playback_quality','baseline'];
    $codes=[];
    foreach((array)($strategy['ideas']??[]) as $idea){
        if(!is_array($idea))continue;
        $code=(string)($idea['code']??'');
        if(in_array($code,$allowed,true)&&!in_array($code,$codes,true))$codes[]=$code;
        if(count($codes)>=8)break;
    }
    return ['eligible_current_opted_in_contacts'=>$eligible,'idea_codes'=>$codes,
        'outreach_requires_live_consent'=>true,'admin_review_required'=>true,
        'marketing_send_executed'=>false,'campaign_saved'=>false];
}
function sf_fan_conversion_agent_copy(array $brief):string {
    $opted=max(0,(int)($brief['eligible_current_opted_in_contacts']??0));
    $codes=(array)($brief['idea_codes']??[]);
    $copy='Current CRM snapshot identifies '.$opted.' opted-in, non-unsubscribed newsletter contacts.';
    if(in_array('newsletter_music_review',$codes,true))
        $copy.=' Consider an unsent public-music newsletter draft in the existing Campaign Builder.';
    elseif(in_array('newsletter_vinyl_review',$codes,true))
        $copy.=' Consider an unsent newsletter draft about published vinyl options.';
    elseif(in_array('collect_evidence',$codes,true))
        $copy.=' Engagement is not yet strong enough to justify a targeted campaign.';
    else $copy.=' Review the on-site improvements and current campaign templates first.';
    return $copy.' Review the exact audience, copy and opt-out controls. A send requires live consent rechecking; nothing was created or sent.';
}
