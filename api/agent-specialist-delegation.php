<?php
declare(strict_types=1);
/**
 * 47G: governed specialist assignment contracts, derived from persisted plans.
 * Delegates ONLY logical read/review work: never delegates a credential,
 * executes a tool, spawns a provider request, or widens the parent's scope.
 */
require_once __DIR__.'/agent-mission-planner.php';

function sf_agent_specialist_kind(string $skillId): ?string {
    return [
      'catalog_discovery'=>'catalog_researcher',
      'public_recommendations'=>'music_recommender',
      'customer_favorites'=>'customer_review_coordinator',
      'customer_playlists'=>'customer_review_coordinator',
      'admin_crm_insights'=>'crm_aggregate_analyst',
      'admin_campaign_insights'=>'journey_aggregate_analyst',
      'admin_campaign_drafts'=>'admin_review_coordinator',
      'admin_journey_drafts'=>'admin_review_coordinator'
    ][$skillId]??null;
}

/**
 * Read source of truth by authenticated owner/scope and never accept a
 * caller-crafted plan or model-provided tool ID as delegation authority.
 */
function sf_agent_specialist_assignments(string $scope,int $ownerId,string $missionId): array {
    if($ownerId<1||!in_array($scope,['customer','admin'],true))return [];
    $plan=sf_agent_mission_plan_load($scope,$ownerId,$missionId);
    if(!$plan||($plan['scope']??'')!==$scope||count($plan['steps']??[])>4)return [];
    $assignments=[];
    foreach($plan['steps'] as $index=>$step){
        if(!is_array($step))return [];
        $intent=(string)($step['intent']??'');
        $contract=sf_agent_orchestration_contract($scope,$intent);
        $skillId=(string)($step['skill_id']??'');
        $kind=sf_agent_specialist_kind($skillId);
        if($kind===null||!$contract['registered']||$contract['skill_id']!==$skillId
            ||$contract['tool_id']!==($step['tool_id']??null)
            ||($step['order']??null)!==$index+1
            ||($step['may_execute_directly']??null)!==false
            ||($step['requires_exact_approval']??null)!==$contract['requires_exact_approval'])
            return [];
        $approval=(bool)$contract['requires_exact_approval'];
        $status=(string)($step['status']??'');
        if($approval && $status!=='approval_required')return [];
        if(!$approval && !in_array($status,['read_available','read_completed'],true))return [];
        $evidence=[];
        if(!$approval && $scope==='admin' && $status==='read_completed'
            && ($step['evidence']['source']??'')==='admin_aggregate') {
            // Only existing trusted operational aggregate read evidence survives.
            if($intent==='crm_report'){
                foreach(['crm_contacts','newsletter_opted_in'] as $key){
                    if(isset($step['evidence'][$key])&&is_numeric($step['evidence'][$key]))
                        $evidence[$key]=max(0,(int)$step['evidence'][$key]);
                }
            }elseif($intent==='journey_report'){
                $row=(array)($step['evidence']['journey_counts']??[]);
                foreach(['journeys_started','journeys_completed','journeys_failed'] as $key){
                    if(isset($row[$key])&&is_numeric($row[$key]))
                        $evidence[$key]=max(0,(int)$row[$key]);
                }
            }
        }
        $assignments[]=[
          'id'=>substr(hash('sha256',$missionId.'|'.$scope.'|'.$ownerId.'|'.($index+1).'|'.$skillId),0,32),
          'order'=>$index+1,
          'specialist'=>$kind,
          'intent'=>$intent,
          'skill_id'=>$skillId,
          'tool_id'=>$contract['tool_id'],
          'status'=>$approval?'awaiting_owner_review':($status==='read_completed'&&$evidence?'read_completed':'read_pending'),
          'evidence_source'=>$evidence?'admin_aggregate':null,
          'evidence'=>$evidence,
          'read_budget'=>1,
          'can_execute_write'=>false,
          'requires_exact_approval'=>$approval
        ];
    }
    return $assignments;
}

function sf_agent_specialist_recent(string $scope,int $ownerId,int $limit=6): array {
    if($ownerId<1||!in_array($scope,['customer','admin'],true))return [];
    $out=[];
    foreach(sf_agent_mission_plan_recent($scope,$ownerId,max(1,min(10,$limit))) as $plan){
        $id=(string)($plan['id']??'');
        $assignments=sf_agent_specialist_assignments($scope,$ownerId,$id);
        if($assignments)$out[]=['mission_id'=>$id,'assignments'=>$assignments];
    }
    return $out;
}
