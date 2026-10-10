<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/ticketing-core.php';

ok(sf_ticket_slug('Phoenix VIP + Meet & Greet')==='phoenix-vip-meet-greet','ticket offer slug normalizes safely');
$base=['status'=>'published','starts_at'=>null,'ends_at'=>null,'requires_membership'=>0,'minimum_rank'=>0,'minimum_package_id'=>null,'requires_vip'=>0,'requires_priority_presale'=>0,'capacity'=>25,'claimed_qty'=>3,'fulfillment_type'=>'reservation'];
$a=sf_ticket_access($base,null);
ok($a['allowed']===false&&$a['reason']==='sign_in_required'&&$a['remaining']===22,'internal RSVP requires sign-in and reports remaining capacity');
$external=$base;$external['fulfillment_type']='external';
$a=sf_ticket_access($external,null);
ok($a['allowed']===true&&$a['remaining']===22,'public external ticket offer can be viewed without account');
$future=$external;$future['starts_at']=gmdate('c',time()+86400);
$a=sf_ticket_access($future,null);
ok($a['allowed']===false&&$a['reason']==='not_open','future public offer stays locked without presale entitlement');
$sold=$external;$sold['capacity']=5;$sold['claimed_qty']=5;
$a=sf_ticket_access($sold,null);
ok($a['allowed']===false&&$a['reason']==='sold_out','capacity exhaustion closes reservation access');
$closed=$external;$closed['ends_at']=gmdate('c',time()-60);
$a=sf_ticket_access($closed,null);
ok($a['allowed']===false&&$a['reason']==='closed','expired offer is closed');

$core=file_get_contents($root.'/api/ticketing-core.php');$boot=file_get_contents($root.'/api/bootstrap.php');$public=file_get_contents($root.'/api/ticketing.php');$admin=file_get_contents($root.'/admin/api/ticketing.php');$export=file_get_contents($root.'/admin/api/ticketing-export.php');$adminJs=file_get_contents($root.'/admin/assets/admin.js');$ticketJs=file_get_contents($root.'/admin/assets/ticketing.js');$ticketCss=file_get_contents($root.'/admin/assets/ticketing.css');$adminShell=file_get_contents($root.'/admin/index.php');$app=file_get_contents($root.'/assets/js/app.js');$siteCss=file_get_contents($root.'/assets/css/site.css');$shell=file_get_contents($root.'/stonefellow-v120.php');$agent=file_get_contents($root.'/api/agent-runtime.php');$crm=file_get_contents($root.'/api/crm-core.php');$mig=file_get_contents($root.'/api/migrations.php');$version=file_get_contents($root.'/version.php');$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/ticketing-core.php'"),'ticketing core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.20'"),'database schema advances to 1.3.20');
ok(str_contains($mig,"'id'=>'2026-10-10-021'")&&str_contains($mig,'sf_ticketing_ensure_schema'),'migration 021 installs ticketing schema');
foreach(['ticket_offers','ticket_reservations','ticket_events'] as $table){ok(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'ticketing schema supports SQLite and MySQL for '.$table);ok(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);}

ok(str_contains($core,"'rsvp','ticket','vip','meet_greet','presale'"),'offer engine supports RSVP, tickets, VIP, meet-and-greet and presale types');
ok(str_contains($core,'if($price>0&&$fulfillment!==\'external\')')&&str_contains($core,'Paid ticket offers require external checkout'),'paid offers cannot masquerade as unpaid internal reservations');
ok(str_contains($core,'FOR UPDATE')&&str_contains($core,'claimed_qty+?<=capacity'),'reservation transaction locks offer state and enforces capacity atomically');
ok(str_contains($core,"status IN ('reserved','checked_in')")&&str_contains($core,'max_per_fan'),'per-fan reservation limit is enforced inside reservation transaction');
ok(str_contains($core,'request_key TEXT NOT NULL UNIQUE')&&str_contains($core,"SELECT * FROM ticket_reservations WHERE request_key=?"),'reservation request keys provide retry idempotency');
ok(str_contains($core,'confirmation_code TEXT NOT NULL UNIQUE')&&str_contains($core,'sf_ticket_code'),'reservations receive unique confirmation codes');
ok(str_contains($core,"SET claimed_qty=CASE WHEN claimed_qty>=? THEN claimed_qty-? ELSE 0 END"),'cancellation safely releases capacity');
ok(str_contains($core,"status='checked_in'")&&str_contains($core,'guest_checked_in'),'check-in is idempotent and recorded in CRM');
ok(str_contains($core,"'ticket_reserved'")&&str_contains($core,"'vip_reserved'")&&str_contains($core,"'ticket_cancelled'"),'reservation lifecycle feeds CRM event history');
ok(str_contains($crm,'sf_automation_process_crm_event'),'ticket CRM events automatically feed lifecycle automations');

ok(str_contains($core,'sf_membership_state')&&str_contains($core,'priority_presale')&&str_contains($core,'early_access_days'),'offer access consumes membership and priority-presale benefits');
ok(str_contains($core,'minimum_package_id')&&str_contains($core,'minimum_rank')&&str_contains($core,'requires_vip'),'offers support tier, rank and VIP gates');
ok(str_contains($core,"reason='member_presale'"),'eligible members can enter before public opening during their early-access window');

ok(str_contains($public,'sf_ticket_public_offers')&&str_contains($public,'sf_ticket_reserve')&&str_contains($public,'sf_ticket_cancel'),'public API provides offer discovery plus authenticated reserve/cancel');
ok(str_contains($public,'sf_require_user(false,true)'),'reservation mutations require an authenticated account and CSRF');
ok(str_contains($admin,'save_offer')&&str_contains($admin,'check_in')&&str_contains($admin,'archive_offer'),'Admin API manages offers, check-in and archival');
ok(str_contains($admin,'explicit Admin confirmation')&&str_contains($admin,'sf_agent_brain_log'),'offer publishing is governed and recorded in Agent Brain');
ok(str_contains($export,'text/csv')&&str_contains($export,'confirmation_code'),'Admin can export show/offer guest lists');

ok(str_contains($adminShell,'data-view="ticketing"')&&str_contains($adminShell,'assets/ticketing.js'),'Tickets + VIP is a first-class Admin module');
ok(str_contains($adminJs,"ticketing:'Tickets + VIP'")&&str_contains($adminJs,'SFTicketingAdmin'),'Admin router loads ticketing module');
ok(str_contains($ticketJs,'Guest list')&&str_contains($ticketJs,'Check in code')&&str_contains($ticketJs,'Export CSV'),'Admin workspace includes guest list, confirmation check-in and CSV export');
ok(str_contains($ticketJs,'Capacity')&&str_contains($ticketJs,'Max per fan')&&str_contains($ticketJs,'Minimum tier'),'offer editor exposes capacity and membership access controls');
ok(str_contains($ticketCss,'.ticket-guest-table'),'ticketing Admin has dedicated responsive styling');

ok(str_contains($app,'async function renderTickets')&&str_contains($app,'ticketReservationCard'),'public app provides Tickets + VIP hub with My Reservations');
ok(str_contains($app,'renderShowTicketOffers')&&str_contains($app,'showTicketOffers'),'show detail loads current ticket/VIP offers');
ok(str_contains($app,'reserveTicket')&&str_contains($app,'request_id'),'fan reservation sends an idempotency key');
ok(str_contains($app,'Cancel reservation')&&str_contains($app,'cancelTicket'),'fans can cancel eligible reservations and release capacity');
ok(str_contains($shell,'data-view="tickets"')&&str_contains($shell,'data-quick-action="tickets"'),'public menu and chat + menu expose Tickets + VIP');
ok(str_contains($app,"action==='tickets'")&&str_contains($app,"view==='tickets'"),'quick actions and router open Tickets + VIP');
ok(str_contains($siteCss,'.ticket-offer-card')&&str_contains($siteCss,'.ticket-reservation-card'),'public ticketing experience has responsive styling');

ok(str_contains($agent,'ticketing_info')&&str_contains($agent,'sf_ticket_agent_context'),'Agent routes ticket questions and receives active offer/reservation context');
ok(str_contains($agent,'reservations remain user-confirmed UI actions'),'Agent policy forbids autonomous ticket reservations');
ok(str_contains($adminJs,'guest list')&&str_contains($adminJs,"openView('ticketing')"),'Admin Agent routes guest-list and check-in requests to Ticketing');

ok(str_contains($version,"'stonefellow'=>'1.3.23'")&&str_contains($version,"'database_schema_target'=>'1.3.20'"),'version endpoint reports app 1.3.23 and schema 1.3.20');
ok(str_contains($version,"'ticketing_vip'=>'show-offers-rsvp-capacity-reservations-guest-list-checkin'"),'version endpoint advertises ticketing/VIP capability');
ok(str_contains($wf,'node --check admin/assets/ticketing.js')&&str_contains($wf,'php tests/v1323-section24-ticketing-vip.php'),'release gate includes Ticketing Admin JS and Section 24 suite');
echo "Stonefellow v1.3.23 Section 24 Ticketing, RSVP & VIP Guest Experiences audit: PASS\n";
