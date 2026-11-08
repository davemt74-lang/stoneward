"""Exercise /me and /notifications through the production composer and drawer."""
import os
from pathlib import Path
import shutil

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()


def block(start, end):
    offset = APP.index(start)
    return APP[offset:APP.index(end, offset)]


COMPOSER = block('  let chatMusicPicker=null,chatMusicCommandTimer=null;', '  function chatImageCommandRange(')
SUBMIT = block('  function submitAgentChat(', '  function canvasBriefInChat(')
DESIGN = block('  function canvasDesignBrief(', '  let manualAgentHidden=')
AUTH = block('  function syncAuthMenu(){', '  async function loadAuth(')
DRAWER = block('  function renderDrawer(){', '  async function markNotification(')
PLAYLISTS = block('  async function loadPlaylists(', '  async function loadAgentListeningSession(')
HTML = ('<!doctype html><html><body><form id="chat">'
        '<label>Prompt<input id="composer" maxlength="2000"></label>'
        '<button type="submit">Send</button></form><p id="notice"></p>'
        '<button id="bell">Notifications <span id="badge" hidden></span></button>'
        '<div id="backdrop" hidden></div>'
        '<aside id="activity" role="dialog" aria-modal="true" aria-label="Activity" hidden>'
        '<button id="close-activity">Close Activity</button><div id="activity-content"></div>'
        '</aside></body></html>')
INVENTORY = [
    {'id': 'personal_listening_stats', 'title': 'Listening by song', 'description': 'Plays and completions by song.',
     'time_window': 'Past 30 days', 'max_rows': 30, 'fields': {'listens': 'number', 'track_id': 'text'},
     'field_labels': {'listens': 'Play starts <b>measured</b>'},
     'image_url': '/private-account-picture', 'price_text': 'PRIVATE_PRICE', 'rows': [{'listens': 'PRIVATE_PLAYS'}]},
    {'id': 'my_listening_summary', 'title': 'Listening summary', 'fields': {'listened_seconds': 'number'}},
    {'id': 'my_listening_history', 'title': 'Recent listening history', 'fields': {'event_type': 'text'}},
    {'id': 'my_purchases', 'title': 'My purchases', 'fields': {'quantity': 'number'}},
    {'id': 'my_tickets', 'title': 'My tickets', 'fields': {'reserved_at': 'text'}},
    {'id': 'my_preferences', 'title': 'My preferences', 'fields': {'timezone': 'text'}},
    {'id': 'my_notifications', 'title': 'Notification inbox', 'fields': {'title': 'text'}},
    {'id': 'disabled_data', 'title': 'Disabled data', 'enabled': False, 'fields': {'restricted': 'text'}},
    {'id': 'invalid id', 'title': 'Invalid data', 'fields': {}},
]
SETUP = """() => {
  window.input=document.querySelector('#composer');
  window.owner='';window.canvasOwner=()=>owner;window.loadAuth=async()=>{};
  window.st={auth:{authenticated:false},drawer:{mode:'activity',tab:'notifications',data:null},queue:{open:false},playlists:{loaded:false,items:[]}};
  window.$=(query,root=document)=>root.querySelector(query);
  window.$$=(query,root=document)=>Array.from(root.querySelectorAll(query));
  window.notificationDrawer=$('#activity');window.notificationBackdrop=$('#backdrop');
  window.notificationClose=$('#close-activity');window.notificationButton=$('#bell');
  window.notificationBadge=$('#badge');window.drawerUnreadCount=null;window.drawerContent=$('#activity-content');
  window.drawerRenderSequence=0;window.drawerReturnFocus=null;window.cfg={apiBase:'/api'};
  window.esc=value=>String(value??'').replaceAll('<','&lt;');
  window.fmtWhen=value=>value;window.notificationActionMarkup=()=>'';window.syncDrawerChrome=()=>{};
  window.navigate=view=>{window.navigatedView=view};
  window.canvas=null;window.voiceTurnBusy=true;
  window.accountPhotoPreferencesRequest=null;window.stopListeningRoomBuild={pending:null};
  window.canvasImageEditDraft=null;window.gallerySceneRequest=null;window.listeningRoomOwner=null;
  window.canvasGalleryView=null;window.canvasAccess={current:null};
  window.isGalleryScene=()=>false;window.closeListeningRoom=()=>{};
  window.syncCanvasDebugOwner=()=>{};window.syncCanvasCodingModelMenu=()=>{};window.canvasProjects=()=>null;
  window.setOwner=value=>{owner=value;st.auth={authenticated:!!value};syncAuthMenu();};
  window.chatImagePicker=null;window.chatImageDraftLimit=2000;window.chatImageReferenceLimit=4;
  window.listeningRoom=null;
  window.popupEntry=(_kind,text)=>document.querySelector('#notice').textContent=text;
  window.command=()=>{throw Error('Picker started a build or sent a chat')};
  Object.defineProperty(Notification,'requestPermission',{value:()=>{throw Error('Requested push permission')}});
  window.api=async(path,body,signal)=>{
    if(body!==undefined)throw Error('Expected an inventory GET');
    const response=await fetch('/api/'+path,{signal});
    return response.json();
  };
}"""

with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        options['executable_path'] = executable
    browser = playwright.chromium.launch(**options)
    for width in (390, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 740}, has_touch=width == 390)
        page = context.new_page()
        errors, requests = [], []
        pending = {}
        flags = {'notifications_enabled': True, 'hold_inbox': False}
        page.on('pageerror', lambda error: errors.append(str(error)))

        def route(handler):
            request = handler.request
            requests.append((request.method, request.url))
            if request.url == 'https://account.test/':
                handler.fulfill(content_type='text/html', body=HTML)
            elif request.url == 'https://account.test/api/canvas-resources.php?kind=me':
                items = [{**item, 'enabled': False} if item['id'] == 'my_notifications'
                         and not flags['notifications_enabled'] else item for item in INVENTORY]
                handler.fulfill(json={'ok': True, 'items': items})
            elif request.url == 'https://account.test/api/notifications.php':
                if flags['hold_inbox']:
                    pending['inbox'] = handler
                else:
                    handler.fulfill(json={'ok': True, 'unread': 1, 'notifications': [
                        {'id': 7, 'title': 'Signed-in notification', 'is_read': 0}]})
            elif request.url == 'https://account.test/api/playlists.php':
                handler.fulfill(json={'ok': True, 'playlists': []})
            else:
                handler.abort()

        page.route('**/*', route)
        page.goto('https://account.test/')
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-resource-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-resource-picker.js').read_text())
        page.evaluate(SETUP)
        page.add_script_tag(content=COMPOSER + SUBMIT + DESIGN + AUTH + DRAWER + PLAYLISTS +
                            "bindChatResourcePicker();document.querySelector('#chat').onsubmit=submitAgentChat;"
                            "notificationClose.onclick=closeNotificationDrawer;"
                            "const loadInbox=loadNotificationDrawer;"
                            "loadNotificationDrawer=()=>window.pendingInbox=loadInbox();")

        composer = page.get_by_label('Prompt')
        picker = page.get_by_role('dialog', name='Choose your account data')
        search = picker.get_by_role('searchbox', name='Search account data or fields')
        listening = picker.get_by_role('checkbox', name='Listening by song', exact=True)
        purchases = picker.get_by_role('checkbox', name='My purchases', exact=True)
        notifications = picker.get_by_role('checkbox', name='Notification inbox', exact=True)
        activity = page.get_by_role('dialog', name='Activity')
        close_activity = activity.get_by_role('button', name='Close Activity')
        add = picker.get_by_role('button', name='Add selections')

        def activate(control):
            control.tap() if width == 390 else control.click()

        def open_picker(draft):
            composer.fill(draft)
            composer.press('Enter')
            expect(picker).to_be_visible()
            expect(search).to_be_focused()
            expect(purchases).to_be_visible()

        composer.fill('/me')
        composer.press('Enter')
        expect(page.locator('#notice')).to_have_text('Sign in to choose your account data.')
        expect(picker).not_to_be_visible()
        assert len(requests) == 1
        assert page.evaluate("chatResourceCommandRange('/merch').kind==='merch'&&chatResourceCommandRange('/mer')===null")

        composer.fill(' /NoTiFiCaTiOnS ')
        composer.press('Enter')
        expect(activity).to_be_visible()
        expect(close_activity).to_be_focused()
        expect(composer).to_have_value('')
        expect(picker).not_to_be_visible()
        assert len(requests) == 1
        activate(activity.get_by_role('button', name='Sign in'))
        expect(activity).not_to_be_visible()
        expect(composer).to_be_focused()
        assert page.evaluate("navigatedView==='login'")
        composer.fill('/edit add /notifications')
        composer.press('Enter')
        expect(page.locator('#notice')).to_have_text('Sign in to choose your account data.')
        expect(composer).to_have_value('/edit add /notifications')
        expect(activity).not_to_be_visible()
        assert len(requests) == 1

        page.evaluate("owner='1'")
        composer.fill('/me')
        page.wait_for_timeout(425)
        expect(picker).not_to_be_visible()
        open_picker('/Me')
        expect(picker.get_by_role('checkbox')).to_have_count(7)
        expect(listening).to_have_accessible_description('Plays and completions by song. · Past 30 days · Up to 30 rows')
        assert 'PRIVATE_' not in picker.inner_text()
        search.fill('play starts')
        expect(listening).to_be_visible()
        expect(purchases).not_to_be_visible()
        activate(picker.get_by_text('2 available fields'))
        expect(picker.get_by_text('Play starts <b>measured</b>')).to_be_visible()
        expect(picker.locator('code').get_by_text('listens', exact=True)).to_be_visible()
        expect(listening).not_to_be_checked()
        assert not picker.locator('b, img').count()
        activate(listening)
        search.fill('quantity')
        activate(purchases)
        activate(add)
        expect(composer).to_be_focused()
        expect(composer).to_have_value('{me:personal_listening_stats} {me:my_purchases}')
        assert page.evaluate('chatDraftResourceInputs(input.value)') == {
            'artist_inputs': {'me_tools': ['personal_listening_stats', 'my_purchases']}}
        assert page.evaluate('canvasDesignBrief(input.value)===""&&input.maxLength>2000')

        page.evaluate("listeningRoom={id:4,artist_inputs:{me_tools:['my_purchases']}}")
        open_picker('/edit Restyle {lyrics:song} {merch:hoodie} /me')
        expect(purchases).to_be_checked()
        activate(purchases)
        activate(add)
        expect(composer).to_have_value('/edit Restyle {lyrics:song} {merch:hoodie} {me-clear}')
        assert page.evaluate('chatDraftResourceInputs(input.value)') == {
            'artist_inputs': {'lyrics_ids': ['song'], 'me_tools': [], 'merch_ids': ['hoodie']}}

        page.evaluate("listeningRoom.artist_inputs.me_tools=['disabled_data']")
        open_picker('/edit {me-clear} /me')
        expect(picker.get_by_role('checkbox', name='Unavailable account data')).not_to_be_visible()
        search.press('Escape')
        expect(composer).to_have_value('/edit {me-clear} /me')

        open_picker('/edit /me')
        unavailable = picker.get_by_role('checkbox', name='Unavailable account data')
        expect(unavailable).to_be_checked()
        expect(add).to_be_disabled()
        activate(unavailable)
        activate(listening)
        page.evaluate("owner='2'")
        activate(add)
        expect(composer).to_have_value('/edit /me')
        assert requests == [
            ('GET', 'https://account.test/'),
            *[('GET', 'https://account.test/api/canvas-resources.php?kind=me')] * 4,
        ]

        assert page.evaluate("chatResourceCommandRange('/notifications')===null&&"
                             "chatResourceCommandRange('/notifications?')===null&&"
                             "chatResourceCommandRange('Discuss /notifications in chat')===null&&"
                             "chatResourceCommandRange('Let’s build a view with /notifications-all')===null&&"
                             "chatResourceCommandRange('Build a list with /notifications').requestedAccountTool==='my_notifications'")
        page.evaluate("setOwner('1');listeningRoom.artist_inputs.me_tools=['my_purchases']")
        draft = '/edit Restyle {lyrics:song} /notifications'
        composer.fill(draft)
        page.wait_for_timeout(425)
        expect(picker).not_to_be_visible()
        open_picker(draft)
        expect(notifications).to_be_checked()
        expect(purchases).to_be_checked()
        expect(composer).to_have_value(draft)
        search.press('Escape')
        expect(composer).to_have_value(draft)
        open_picker(draft)
        activate(add)
        expect(composer).to_have_value('/edit Restyle {lyrics:song} {me:my_purchases} {me:my_notifications}')
        assert page.evaluate('chatDraftResourceInputs(input.value)') == {
            'artist_inputs': {'lyrics_ids': ['song'], 'me_tools': ['my_purchases', 'my_notifications']}}

        draft = 'Let’s build a panel with {me:my_preferences} /notifications'
        open_picker(draft)
        expect(notifications).to_be_checked()
        expect(purchases).not_to_be_checked()
        expect(picker.get_by_role('checkbox', name='My preferences', exact=True)).to_be_checked()
        activate(add)
        assert page.evaluate('chatDraftResourceInputs(input.value)') == {
            'artist_inputs': {'me_tools': ['my_preferences', 'my_notifications']}}
        flags['notifications_enabled'] = False
        composer.fill(draft)
        composer.press('Enter')
        expect(picker.get_by_role('status')).to_have_text('Notifications are unavailable for canvases.')
        expect(add).to_be_disabled()
        expect(notifications).not_to_be_visible()
        search.press('Escape')
        expect(composer).to_have_value(draft)
        flags['notifications_enabled'] = True
        open_picker(draft)
        expect(notifications).to_be_checked()
        page.evaluate("setOwner('2')")
        expect(picker).not_to_be_visible()
        expect(composer).to_have_value(draft)
        page.evaluate("setOwner('1')")

        composer.fill('/notifications')
        composer.press('Enter')
        expect(activity.get_by_text('Signed-in notification')).to_be_visible()
        expect(close_activity).to_be_focused()
        expect(page.locator('#badge')).to_have_text('1')
        expect(picker).not_to_be_visible()
        activate(close_activity)
        expect(composer).to_be_focused()
        flags['hold_inbox'] = True
        with page.expect_request('**/api/notifications.php'):
            composer.fill('/notifications')
            composer.press('Enter')
        expect(activity.get_by_text('Signed-in notification')).to_be_visible()
        page.evaluate("setOwner('')")
        expect(activity.get_by_text('Signed-in notification')).not_to_be_visible()
        expect(activity.get_by_role('button', name='Sign in')).to_be_visible()
        expect(page.locator('#badge')).to_be_hidden()
        pending.pop('inbox').fulfill(json={'ok': True, 'unread': 4, 'csrf': 'old', 'notifications': [
            {'id': 9, 'title': 'Previous account only'}]})
        page.evaluate("pendingInbox")
        expect(activity.get_by_text('Previous account only')).not_to_be_visible()
        expect(page.locator('#badge')).to_be_hidden()
        assert page.evaluate("st.drawer.data===null&&st.auth.csrf===undefined")
        assert not errors and requests[5:] == [
            *[('GET', 'https://account.test/api/canvas-resources.php?kind=me')] * 5,
            *[('GET', 'https://account.test/api/notifications.php'),
              ('GET', 'https://account.test/api/playlists.php')] * 2,
        ]
        print(f'PASS chromium/{width}: /me and /notifications attachment, local inbox, and account boundaries', flush=True)
        context.close()
    browser.close()
