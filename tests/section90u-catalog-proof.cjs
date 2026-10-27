'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const catalog=read('admin/assets/admin.js');
const gallery=read('admin/assets/media.js');
const api=read('admin/api/media-catalog.php');
const proof=read('admin/api/activity-proof.php');
const agent=read('admin/assets/agent-canvas.js');
const shell=read('admin/index.php');
const releases=read('admin/api/releases.php');
const shows=read('admin/api/shows.php');
const merch=read('admin/api/products.php');

assert(api.includes("if(($_SERVER['REQUEST_METHOD']??'')==='GET')")&&api.includes('sf_admin_require_auth(false)'),
  'Private Catalog entries have an authenticated read-only listing.');
assert(api.includes('sf_agent_song_intake_list((int)$me[\'id\'])')
  &&api.includes("if(($draft['status']??'')==='published')continue"),
  'Private listing is scoped to owner and excludes already-public tracks.');
assert(catalog.includes('Private Catalog drafts')&&catalog.includes('data-draft-review')
  &&catalog.includes('data-draft-publish')&&catalog.includes("api('media-catalog.php',{action:'publish_draft'"),
  'Admin Catalog shows saved drafts with edit and authenticated Publish actions.');
assert(/function renderCatalog\(\)[\s\S]*?\$\('#catalogDraftRefresh'\)\.onclick=\(\)=>loadCatalogDrafts\(\);[\s\S]*?renderCatalogRows\(\);\s*void loadCatalogDrafts\(\);/.test(catalog),
  'Private drafts load on initial Catalog navigation without requiring a search input event.');
assert(catalog.includes('rights_confirmed:true,confirmed:true')&&catalog.includes('window.confirm('),
  'Public publication still requires a separate explicit rights confirmation.');
assert(gallery.includes("openView('catalog')")&&gallery.includes('Saved as a PRIVATE Catalog draft'),
  'Adding a song without Auto-publish immediately opens the Admin Catalog draft view.');
assert(catalog.includes("api('catalog.php').then")&&catalog.includes('renderCatalogRows()'),
  'Admin Catalog refreshes public records from the server after navigation.');
assert(catalog.includes("$$('[data-g-credit]',host).forEach")
  &&catalog.includes("$$('[data-g-title]',host).forEach"),
  'Folder-scan per-song metadata and title bind all inputs rather than a single node.');
assert(shell.includes('id="adminNotificationsButton"')&&shell.includes('id="adminNotificationsCount"'),
  'Right-aligned Admin header has a notification button and badge.');
assert(agent.includes('data-brain-tab="activity"')&&agent.includes('data-brain-tab="brain"')
  &&agent.includes('data-brain-tab="history"'),
  'Header slide-out has Notifications, Agent Brain and History tabs.');
assert(agent.includes('function proofCard(')&&agent.includes('function loadProofsInto(')
  &&agent.includes('id="adminAgentProofCards"')&&agent.includes('adminChatPopupProof'),
  'Main Admin Agent chat and popup show independently loaded proof cards.');
assert(proof.includes('sf_admin_require_auth(false)')&&proof.includes('sf_ops_recent_admin_audit(180)')
  &&proof.includes("(int)($entry['admin_user_id']??0)!==$owner"),
  'Activity history reads owner-scoped persisted audit evidence, never unscoped user logs.');
for(const check of ['sf_catalog()','sf_admin_releases()','sf_admin_shows()',
  'sf_commerce_product($id,false)','sf_media_asset((int)$id)','sf_agent_song_intake_list($owner)'])
  assert(proof.includes(check),'Action proof independently checks a saved entity: '+check);
assert(proof.includes("'verified'=>$verified")&&proof.includes("$state='audit only'"),
  'Unverifiable actions stay labeled audit-only; no fabricated completion cards.');
assert(releases.includes("'release_created'")&&shows.includes("'live_show_created'")
  &&merch.includes("'store_product_saved'"),
  'Albums, live shows and merch all contribute persisted action receipts.');
assert(catalog.includes("deep.get('release')")&&catalog.includes("deep.get('show')")
  &&catalog.includes("deep.get('product')")&&catalog.includes("deep.get('track')"),
  'Verification links reopen the exact Album, Show, Merch or Catalog record.');
console.log('Section 90U Catalog visibility, notification drawer and verified action cards: PASS');
