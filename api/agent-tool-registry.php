<?php
declare(strict_types=1);
/**
 * Section 44: server-authoritative Agent tool inventory.
 * Registry metadata is descriptive; entries never grant execution authority.
 */
function sf_agent_tool_registry(): array {
    return [
        'public_catalog_search'=>['label'=>'Public catalog discovery','kind'=>'read','scope'=>'fan','owner'=>'api/search-core.php','handler'=>'sf_catalog_search','confirmation'=>false,'execution'=>'server'],
        'published_catalog'=>[
            'label'=>'Published track catalog','kind'=>'read','scope'=>'fan','owner'=>'api/site-data.php',
            'handler'=>'sf_public_catalog','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'Published Stonefellow songs with release names and track duration in seconds.',
                'fields'=>['track_id'=>'text','title'=>'text','release'=>'text','duration_seconds'=>'number'],
                'filters'=>['track_id'],
                'max_rows'=>120,
                'actions'=>['play_track'=>['field'=>'track_id']],
            ],
        ],
        'published_release_tracks'=>[
            'label'=>'Published release track lists','kind'=>'read','scope'=>'fan','owner'=>'api/agent-canvas-data.php',
            'handler'=>'sf_agent_canvas_release_track_rows','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'Currently public songs grouped by their published release, in the release’s track order. Filter release_id to load the requested release’s track list.',
                'fields'=>['release_id'=>'text','release_title'=>'text','track_id'=>'text','title'=>'text','duration_seconds'=>'number','position'=>'number'],
                'filters'=>['release_id','track_id'],
                'max_rows'=>120,
                'actions'=>['play_track'=>['field'=>'track_id']],
            ],
        ],
        'published_stories'=>[
            'label'=>'Published artist stories','kind'=>'read','scope'=>'fan','owner'=>'api/canvas-stories-core.php',
            'handler'=>'sf_canvas_story_rows','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'Currently published artist stories in the selected order, the current All Stories collection, or the selected tag collection. Filter exact story_id or public relationship fields. Summary and body are bounded to 640 and 4,000 Unicode characters with explicit truncation fields. Bind live fields for story text. A view mounts at most 120 matching rows and can declare an sf-data-truncated child with sf-field-rendered_count and sf-field-matching_count to display the limit.',
                'fields'=>[
                    'story_id'=>'text','title'=>'text','summary'=>'long_text','summary_truncated'=>'number',
                    'body_text'=>'long_text','body_truncated'=>'number','entry_type'=>'text','event_date'=>'text','era'=>'text','tags'=>'text',
                    'related_type'=>'text','related_id'=>'text','related_title'=>'text',
                    'track_id'=>'text','release_id'=>'text','show_id'=>'text',
                ],
                'filters'=>['story_id','entry_type','related_type','related_id','release_id','show_id'],
                'max_rows'=>120,
                'actions'=>['play_track'=>['field'=>'track_id']],
            ],
        ],
        'published_lyrics'=>[
            'label'=>'Published song lyrics','kind'=>'read','scope'=>'fan','owner'=>'api/canvas-lyrics-core.php',
            'handler'=>'sf_canvas_lyrics_rows','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'Published Songbook text and separately approved timeline cues for the selected songs or an exact canonical track filter. Text rows are untimed. Cue rows have saved millisecond ranges and a timing source, with estimated ranges marked approximate. Use kind=text for canonical whole-song text and kind=cue for timed lines. A timeline-only track has cue rows alone. Bind text or read delivered rows from the inert sfCanvasLyrics JSON element. If the delivery budget is reached, narrow the exact song, kind or cue position filters.',
                'fields'=>[
                    'track_id'=>'text','title'=>'text','kind'=>'text','position'=>'number',
                    'source'=>'text','revision_hash'=>'text','text'=>'long_text',
                    'start_ms'=>'number','end_ms'=>'number','timing_source'=>'text','approximate'=>'number',
                    'text_truncated'=>'number','cues_truncated'=>'number',
                ],
                'filters'=>['track_id','kind','position'],
                'max_rows'=>120,
            ],
        ],
        'personal_listening_stats'=>[
            'label'=>'Personal listening statistics','kind'=>'read','scope'=>'signed_in_fan','owner'=>'api/listening.php',
            'handler'=>'sf_listening_analytics','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'The signed-in listener’s own play starts, completions and observed listening seconds per published song over the past 30 days, ordered by play starts. Listening seconds measure reported native playback, excluding seeking, pauses and buffering. measured_events identifies reports with this evidence; legacy_events identifies older or unsupported reports with no measured time, so zero measured seconds does not imply no historical listening.',
                'fields'=>['track_id'=>'text','title'=>'text','release'=>'text','listens'=>'number','completes'=>'number','listened_seconds'=>'number','measured_events'=>'number','legacy_events'=>'number'],
                'filters'=>['track_id'],
                'max_rows'=>30,
            ],
        ],
        'published_merch'=>[
            'label'=>'Published merchandise','kind'=>'read','scope'=>'fan','owner'=>'api/storefront.php',
            'handler'=>'sf_commerce_products','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'Active Stonefellow merchandise. Each row is a purchasable variant, or the base product if there are no variants. Includes size, color, price and stock status.',
                'fields'=>[
                    'product_id'=>'text','variant_id'=>'text','title'=>'text','variant_title'=>'text',
                    'category'=>'text','description'=>'text','size'=>'text','color'=>'text','options'=>'text',
                    'price_cents'=>'number','currency'=>'text','price_text'=>'text','available'=>'number',
                    'availability'=>'text','availability_label'=>'text',
                ],
                'filters'=>['product_id','variant_id','category','size','color','available','availability'],
                'max_rows'=>120,
            ],
        ],
        'published_ticket_offers'=>[
            'label'=>'Published ticket offers','kind'=>'read','scope'=>'fan','owner'=>'api/ticketing.php',
            'handler'=>'sf_ticket_public_offers','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'Published ticket and reservation offers for public Stonefellow shows, with show details, price and public availability. Use price_text for display. Unlisted external prices have no numeric value.',
                'fields'=>[
                    'offer_id'=>'text','show_id'=>'text','show_title'=>'text','show_date'=>'text','show_status'=>'text',
                    'venue'=>'text','location'=>'text','title'=>'text','offer_type'=>'text','description'=>'text',
                    'fulfillment_type'=>'text','price_cents'=>'number','currency'=>'text','price_text'=>'text',
                    'available'=>'number','availability'=>'text','availability_label'=>'text','starts_at'=>'text','ends_at'=>'text',
                ],
                'filters'=>['offer_id','show_id','show_status','offer_type','fulfillment_type','available','availability'],
                'max_rows'=>60,
            ],
        ],
        'public_site_search'=>['label'=>'Published site search','kind'=>'read','scope'=>'fan','owner'=>'api/search-core.php','handler'=>'sf_site_search','confirmation'=>false,'execution'=>'server'],
        'public_artist_facts'=>['label'=>'Read verified public music and artist facts','kind'=>'read','scope'=>'fan','owner'=>'api/agent-public-artist-context.php','handler'=>'sf_agent_public_artist_provider_context','confirmation'=>false,'execution'=>'server'],
        'admin_catalog_inventory'=>['label'=>'Inspect bounded private song catalog statuses and missing metadata','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-reads.php','handler'=>'sf_agent_admin_catalog_read','confirmation'=>false,'execution'=>'server'],
        'admin_user_usage_summary'=>['label'=>'Read aggregate customer counts and metered AI token usage without identifiers','kind'=>'read','scope'=>'admin','owner'=>'api/user-usage-report.php','handler'=>'sf_admin_user_usage_report','confirmation'=>false,'execution'=>'server'],
        'admin_release_inventory'=>['label'=>'Inspect bounded private release status and schedules','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-reads.php','handler'=>'sf_agent_admin_release_read','confirmation'=>false,'execution'=>'server'],
        'admin_show_inventory'=>['label'=>'Inspect bounded private live-show calendar and cancellation status','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-reads.php','handler'=>'sf_agent_admin_shows_read','confirmation'=>false,'execution'=>'server'],
        'admin_knowledge_inventory'=>['label'=>'Inspect bounded private Knowledge Base file visibility, without contents','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-reads.php','handler'=>'sf_agent_admin_knowledge_read','confirmation'=>false,'execution'=>'server'],
        'admin_media_inventory'=>['label'=>'Inspect bounded private Media Library asset categories and usage','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-reads.php','handler'=>'sf_agent_admin_media_source','confirmation'=>false,'execution'=>'server'],
        'admin_media_attachment_inventory'=>['label'=>'Inspect bounded media attachments and entity relationships','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-reads.php','handler'=>'sf_agent_admin_attachment_source','confirmation'=>false,'execution'=>'server'],
        'recommend_public_track'=>['label'=>'Public track recommendation','kind'=>'read','scope'=>'fan','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_recommend_track','confirmation'=>false,'execution'=>'server'],
        'audio_player_transport'=>[
            'label'=>'Local Stonefellow audio player transport and status',
            'kind'=>'local_action','scope'=>'fan','owner'=>'assets/js/agent-audio-player-skill.js',
            'handler'=>'dispatch','confirmation'=>false,'execution'=>'browser',
            'capabilities'=>['play_track','pause_player','stop_player','resume_player','next_track','previous_track','player_status','set_player_volume','load_temporary_playlist'],
            'contract'=>'stonefellow.audio-player.v1',
        ],
        'favorite_track'=>['label'=>'Favorite a track','kind'=>'write','scope'=>'signed_in_fan','owner'=>'api/personalization.php','handler'=>'set_favorite','confirmation'=>true,'execution'=>'authenticated_api'],
        'create_playlist'=>['label'=>'Create a playlist','kind'=>'write','scope'=>'signed_in_fan','owner'=>'api/playlists.php','handler'=>'create','confirmation'=>true,'execution'=>'authenticated_api'],
        'queue_track'=>['label'=>'Change player queue','kind'=>'local_action','scope'=>'fan','owner'=>'assets/js/app.js','handler'=>'executeAgentAction','confirmation'=>false,'execution'=>'browser'],
        'public_page_navigate'=>['label'=>'Open installed public pages without reloading the player','kind'=>'local_action','scope'=>'fan','owner'=>'assets/js/app.js','handler'=>'executeAgentAction','confirmation'=>false,'execution'=>'browser'],
        'canvas_discussion'=>[
            'label'=>'Discuss private canvas experiences, review selected designs and prepare a project concept for approval',
            'kind'=>'local_action','scope'=>'signed_in_fan','owner'=>'api/canvas-projects.php',
            'handler'=>'discuss','confirmation'=>false,'execution'=>'browser',
            'capabilities'=>['canvas_discuss'],'contract'=>'stonefellow.canvas-discussion.v1',
        ],
        'kunaki_publishing_queue'=>['label'=>'Stage purchased vinyl for HomeServer publishing review','kind'=>'write','scope'=>'admin','owner'=>'admin/api/vinyl-production.php','handler'=>'queue_kunaki_publishing','confirmation'=>true,'execution'=>'governed_admin_api'],
        'stem_mix_inspect'=>['label'=>'Inspect private REAPER mix metrics (Admin only)','kind'=>'read','scope'=>'admin','owner'=>'api/stem-agent-bridge.php','handler'=>'sf_stem_agent_review','confirmation'=>false,'execution'=>'server'],
        'stem_automation_update'=>['label'=>'Apply exactly approved private Stem Studio automation','kind'=>'write','scope'=>'admin','owner'=>'api/stem-agent-bridge.php','handler'=>'sf_stem_project_update','confirmation'=>true,'execution'=>'governed_admin_api'],
        'commerce_aggregate'=>['label'=>'Store payment, refund and fulfillment queue','kind'=>'read','scope'=>'admin','owner'=>'api/agent-commerce-bridge.php','handler'=>'sf_agent_commerce_admin_snapshot','confirmation'=>false,'execution'=>'server'],
        'admin_ticket_inventory'=>['label'=>'Read authenticated ticket offer, reservation and check-in counts','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-llm-router.php','handler'=>'sf_admin_agent_commerce_read','confirmation'=>false,'execution'=>'server'],
        'admin_merch_inventory'=>['label'=>'Read authenticated merchandise products, stock and recorded units','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-llm-router.php','handler'=>'sf_admin_agent_commerce_read','confirmation'=>false,'execution'=>'server'],
        'admin_vinyl_inventory'=>['label'=>'Read authenticated vinyl listings and POD handoff counts','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-llm-router.php','handler'=>'sf_admin_agent_commerce_read','confirmation'=>false,'execution'=>'server'],
        'admin_membership_inventory'=>['label'=>'Read authenticated membership and published-content aggregates','kind'=>'read','scope'=>'admin','owner'=>'api/agent-admin-llm-router.php','handler'=>'sf_admin_agent_commerce_read','confirmation'=>false,'execution'=>'server'],
        'commerce_followup_add'=>['label'=>'Save exactly approved customer order follow-up','kind'=>'write','scope'=>'admin','owner'=>'api/commerce-operations.php','handler'=>'sf_commerce_ops_update','confirmation'=>true,'execution'=>'governed_admin_api'],
        'customer_order_status'=>['label'=>'Read only signed-in customer order status','kind'=>'read','scope'=>'signed_in_fan','owner'=>'api/agent-commerce-bridge.php','handler'=>'sf_agent_commerce_customer_snapshot','confirmation'=>false,'execution'=>'server'],
        'my_purchases'=>[
            'label'=>'My purchases','kind'=>'read','scope'=>'signed_in_fan','owner'=>'api/account.php',
            'handler'=>'sf_agent_canvas_purchase_rows','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'The signed-in customer’s recent order history, one row per purchased item. Order totals and status repeat on each line of that order. Includes recent custom vinyl production status when available.',
                'fields'=>['purchase_id'=>'text','order_id'=>'text','item_label'=>'text','item_type'=>'text',
                    'product_id'=>'text','variant_id'=>'text','track_id'=>'text','release_id'=>'text','quantity'=>'number',
                    'item_total_cents'=>'number','order_total_cents'=>'number','currency'=>'text','ordered_at'=>'text',
                    'order_status'=>'text','payment_status'=>'text','fulfillment_status'=>'text','vinyl_status'=>'text'],
                'filters'=>['purchase_id','order_id','item_type','product_id','variant_id','track_id','release_id',
                    'order_status','payment_status','fulfillment_status','vinyl_status'],
                'max_rows'=>120,
            ],
        ],
        'my_tickets'=>[
            'label'=>'My tickets','kind'=>'read','scope'=>'signed_in_fan','owner'=>'api/ticketing.php',
            'handler'=>'sf_agent_canvas_owned_ticket_rows','confirmation'=>false,'execution'=>'server',
            'canvas_data'=>[
                'description'=>'The signed-in listener’s own ticket reservations, with quantity and status. Published show details are included when available. External ticket purchases are handled by their provider.',
                'fields'=>['reservation_id'=>'text','offer_id'=>'text','show_id'=>'text','offer_title'=>'text',
                    'offer_type'=>'text','show_title'=>'text','show_date'=>'text','venue'=>'text','location'=>'text',
                    'quantity'=>'number','status'=>'text','reserved_at'=>'text'],
                'filters'=>['reservation_id','offer_id','show_id','offer_type','status','show_date'],
                'max_rows'=>60,
            ],
        ],
        'crm_aggregate'=>['label'=>'CRM aggregate overview','kind'=>'read','scope'=>'admin','owner'=>'api/crm-core.php','handler'=>'sf_crm_admin_summary','confirmation'=>false,'execution'=>'server'],
        'campaign_aggregate'=>['label'=>'Campaign aggregate overview','kind'=>'read','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_admin_summary','confirmation'=>false,'execution'=>'server'],
        'campaign_graph_validate'=>['label'=>'Validate campaign node graph','kind'=>'read','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_graph_validate','confirmation'=>false,'execution'=>'server'],
        'admin_module_navigate'=>['label'=>'Open an installed Admin workflow, including user-selected audio upload','kind'=>'local_action','scope'=>'admin','owner'=>'admin/assets/agent-canvas.js','handler'=>'action','confirmation'=>false,'execution'=>'browser'],
        'agent_song_draft_save'=>['label'=>'Review and save an owner-only, unpublished song intake draft','kind'=>'write','scope'=>'admin','owner'=>'admin/api/agent-song-intake.php','handler'=>'save_draft','confirmation'=>true,'execution'=>'governed_admin_api'],
        'agent_song_catalog_prepare'=>['label'=>'Approve private song artwork and rights preflight; no publication','kind'=>'write','scope'=>'admin','owner'=>'admin/api/agent-song-intake.php','handler'=>'prepare_catalog','confirmation'=>true,'execution'=>'governed_admin_api'],
        'agent_song_catalog_publish'=>['label'=>'Publish one separately approved catalog track with a distinct MP3 listening preview','kind'=>'consequential','scope'=>'admin','owner'=>'admin/api/agent-song-catalog.php','handler'=>'publish_track','confirmation'=>true,'execution'=>'governed_admin_api'],
        'catalog_metadata_update'=>['label'=>'Review and save an existing song title, genre, story or year','kind'=>'write','scope'=>'admin','owner'=>'api/agent-catalog-actions.php','handler'=>'sf_agent_catalog_apply_snapshot','confirmation'=>true,'execution'=>'governed_admin_api'],
        'knowledge_note_update'=>['label'=>'Review and save one private Knowledge Base document description','kind'=>'write','scope'=>'admin','owner'=>'api/agent-knowledge-actions.php','handler'=>'sf_agent_knowledge_save_approved_note','confirmation'=>true,'execution'=>'governed_admin_api'],
        'media_caption_update'=>['label'=>'Review and save one Media Library asset caption','kind'=>'write','scope'=>'admin','owner'=>'api/agent-media-actions.php','handler'=>'sf_agent_media_save_approved_caption','confirmation'=>true,'execution'=>'governed_admin_api'],
        'media_alt_text_update'=>['label'=>'Review and save accessibility alt text on one image asset','kind'=>'write','scope'=>'admin','owner'=>'api/agent-media-actions.php','handler'=>'sf_agent_media_save_approved_alt_text','confirmation'=>true,'execution'=>'governed_admin_api'],
        'media_private_link_add'=>['label'=>'Review and add one private image attachment to a real song or release','kind'=>'write','scope'=>'admin','owner'=>'api/agent-media-link-actions.php','handler'=>'sf_agent_media_save_approved_private_link','confirmation'=>true,'execution'=>'governed_admin_api'],
        'automation_draft_save'=>['label'=>'Save reviewed lifecycle journey draft','kind'=>'write','scope'=>'admin','owner'=>'api/automation-core.php','handler'=>'sf_automation_save','confirmation'=>true,'execution'=>'governed_admin_api'],
        'campaign_draft_save'=>['label'=>'Save campaign draft','kind'=>'write','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_save','confirmation'=>true,'execution'=>'governed_admin_api'],
        'campaign_publish'=>['label'=>'Publish a campaign','kind'=>'consequential','scope'=>'admin','owner'=>'admin/api/campaigns.php','handler'=>'save','confirmation'=>true,'execution'=>'governed_admin_api'],
        'campaign_send_email'=>['label'=>'Send campaign email','kind'=>'consequential','scope'=>'admin','owner'=>'api/campaign-core.php','handler'=>'sf_campaign_send_email_node','confirmation'=>true,'execution'=>'governed_admin_api'],
        'elevenlabs_voice'=>['label'=>'Voice synthesis','kind'=>'metered','scope'=>'signed_in_fan','owner'=>'api/agent-voice.php','handler'=>'sf_ai_resolve_voice','confirmation'=>false,'execution'=>'provider'],
        'llm_response'=>['label'=>'OpenAI/Claude reply','kind'=>'metered','scope'=>'signed_in_fan','owner'=>'api/agent-runtime.php','handler'=>'sf_ai_llm_candidates','confirmation'=>false,'execution'=>'provider'],
        'jev_routing'=>['label'=>'JEV policy routing','kind'=>'metered','scope'=>'signed_in_fan','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_jev_route','confirmation'=>false,'execution'=>'provider'],
        'agent_brain_log'=>['label'=>'Agent Brain audit','kind'=>'internal','scope'=>'server','owner'=>'api/agent-runtime.php','handler'=>'sf_agent_brain_log','confirmation'=>false,'execution'=>'server'],
    ];
}
function sf_agent_tool_inventory(string $role='fan'): array {
    $result=[];
    foreach(sf_agent_tool_registry() as $name=>$tool) {
        if($tool['scope']==='server')continue;
        if($tool['scope']==='admin'&&$role!=='admin')continue;
        if($tool['scope']==='signed_in_fan'&&!in_array($role,['fan','admin'],true))continue;
        $result[]=['id'=>$name]+$tool;
    }
    return $result;
}
function sf_agent_tool_allowed(string $toolId,string $role,bool $confirmed=false): bool {
    $tool=sf_agent_tool_registry()[$toolId]??null;
    if(!$tool || $tool['scope']==='server')return false;
    if($tool['scope']==='admin'&&$role!=='admin')return false;
    if($tool['scope']==='signed_in_fan'&&!in_array($role,['fan','admin'],true))return false;
    if(!empty($tool['confirmation'])&&!$confirmed)return false;
    return true;
}

/**
 * Agent execution is an explicit narrow allowlist separate from tool discovery.
 * Sending, publishing, purchasing and deletes remain in owner-controlled UI.
 */
function sf_agent_tool_executable(string $toolId,string $role,bool $confirmed=false): bool {
    if(!in_array($toolId,['campaign_draft_save','automation_draft_save','commerce_followup_add','stem_automation_update','catalog_metadata_update','knowledge_note_update','media_caption_update','media_alt_text_update','media_private_link_add'],true))return false;
    return sf_agent_tool_allowed($toolId,$role,$confirmed);
}
