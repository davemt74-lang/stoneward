<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/agent-admin-brain.php';
$passed=0;
$check=static function(bool $yes,string $label)use(&$passed):void{
    if(!$yes){fwrite(STDERR,"91B6B FAIL $label\n");exit(1);}
    $passed++;echo "91B6B PASS $label\n";
};
$safe=sf_admin_agent_llm_safe_words('How many VIP tickets for Ada Lovelace, order SF-TEST-123, ada@example.com?');
$check(str_contains($safe,'how many vip tickets'),'Classification retains harmless intent words');
$check(!str_contains($safe,'ada')&&!str_contains($safe,'SF-')&&!str_contains($safe,'example')&&!str_contains($safe,'lovelace'),'PII, order IDs and names are not exported');
$check(sf_admin_agent_llm_validate('{"intent":"ticketing_inventory_read"}')['view']==='ticketing','Model may suggest ticket read');
$check(sf_admin_agent_llm_validate('{"intent":"vinyl_inventory_read"}')['view']==='pod','Model may suggest vinyl read');
$check(sf_admin_agent_llm_validate('{"intent":"merch_inventory_read"}')['view']==='products','Model may suggest merch read');
$check(sf_admin_agent_llm_validate('{"intent":"membership_inventory_read"}')['view']==='membership','Model may suggest membership read');
$check(sf_admin_agent_llm_validate('{"intent":"refund_customer"}')===null,'Invented refund intent denied');
$check(sf_admin_agent_llm_validate('{"intent":"campaign_send_email"}')===null,'Model cannot send outbound mail');
$check(sf_admin_agent_llm_validate('not json')===null,'Malformed JSON rejected');
$check(!sf_admin_agent_llm_eligible('Create campaign for a vinyl release',sf_admin_agent_plan('Create campaign for a vinyl release')),'Confirmed campaign draft parser is protected');
$check(!sf_admin_agent_llm_eligible('Set caption for media asset 123 to beach photo',sf_admin_agent_plan('Set caption for media asset 123 to beach photo')),'Exact reviewed media edit is protected');
$check(!sf_admin_agent_llm_eligible('Check order SF-TEST-123',['type'=>'commerce_report','requires_confirmation'=>false]),'Private order identifier never goes to model');
$check(!sf_admin_agent_llm_eligible('Review private invoices',['type'=>'research','requires_confirmation'=>false]),'Explicitly private Admin prompt remains local');
$check(sf_admin_agent_llm_eligible('How many merchandise products are low in stock',['type'=>'research','requires_confirmation'=>false]),'Sanitized commerce question can use LLM');
foreach(['ticketing_inventory_read'=>'admin_ticket_inventory','merch_inventory_read'=>'admin_merch_inventory',
          'vinyl_inventory_read'=>'admin_vinyl_inventory','membership_inventory_read'=>'admin_membership_inventory'] as $intent=>$tool){
    $contract=sf_agent_orchestration_contract('admin',$intent);
    $check($contract['tool_id']===$tool && $contract['may_read_or_navigate'] &&
        !$contract['requires_exact_approval'] && !$contract['may_execute_directly'],"$intent authenticated read only");
    $check(!sf_agent_orchestration_contract('customer',$intent)['registered'],"$intent never exposed to fan");
    $check(!sf_agent_tool_executable($tool,'admin',true),"$intent never an executable write");
}
foreach(['How many ticket reservations'=>'ticketing_inventory_read',
          'Show vinyl inventory status'=>'vinyl_inventory_read',
          'How many merch products are low stock'=>'merch_inventory_read',
          'Count active memberships'=>'membership_inventory_read'] as $request=>$type){
    $check(sf_admin_agent_plan($request)['type']===$type,"Offline fallback for $type");
}
$check(sf_admin_agent_plan('Create a newsletter campaign')['type']==='prepare_campaign','Human-approved campaign drafting is unchanged');
$check(sf_admin_agent_plan('Show my media attachments')['type']==='media_attachment_read','Private media reads keep the existing auth path');
echo "Section 91B6B: $passed Admin LLM intent and commerce permission assertions passed\n";
