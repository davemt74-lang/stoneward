"""Exercise multi-source /data selection through the production picker and composer."""
import json
import os
from pathlib import Path
import shutil
from urllib.parse import parse_qs, urlsplit

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'assets/js/app.js').read_text()


def block(start, end):
    offset = APP.index(start)
    return APP[offset:APP.index(end, offset)]


COMPOSER = block('  let chatMusicPicker=null,chatMusicCommandTimer=null;', '  function chatImageCommandRange(')
SUBMIT = block('  function submitAgentChat(', '  function canvasBriefInChat(')
DESIGN = block('  function canvasDesignBrief(', '  let manualAgentHidden=')
HTML = ('<!doctype html><html><body><form id="chat">'
        '<label>Prompt<input id="composer" maxlength="2000"></label>'
        '<button type="submit">Send</button></form><p id="notice"></p></body></html>')
PARAMETERS = {'location_id': {'type': 'public_place', 'label': 'City or venue'}}
VENUE_SCOPE = {'id': 'published-shows', 'title': 'Any upcoming published venue', 'kind': 'scope'}
WEATHER_PARAMETERS = {'location_id': {**PARAMETERS['location_id'], 'scope_options': [
    VENUE_SCOPE,
    {'id': 'https://example.invalid/location', 'title': 'URL must be rejected', 'kind': 'scope'},
    {'id': '33.45,-112.07', 'title': 'Coordinates must be rejected', 'kind': 'scope'},
]}}
PRODUCTS = [
    {'id': 'weather.current', 'title': 'Current conditions', 'description': 'Recent conditions <b>observed</b>.',
     'fields': {'temperature_f': 'number', 'observed_at': 'text'}, 'parameters': WEATHER_PARAMETERS},
    {'id': 'weather.hourly', 'title': 'Hourly forecast',
     'fields': {'start_at': 'text'}, 'parameters': WEATHER_PARAMETERS},
    {'id': 'maps.points', 'title': 'Map points', 'description': 'Generic source registered by a second service.',
     'fields': {'latitude': 'number'}, 'parameters': PARAMETERS},
    {'id': 'weather.daily', 'title': 'Disabled forecast', 'enabled': False, 'parameters': PARAMETERS},
    {'id': 'malformed', 'title': 'Malformed product', 'parameters': PARAMETERS},
]
VENUE = {'id': 'show-valley', 'title': 'Valley Theatre · Phoenix, AZ', 'kind': 'venue'}
PHOENIX = {'id': 'city-0455000', 'title': 'Phoenix, AZ', 'kind': 'city'}
TUCSON = {'id': 'city-0477000', 'title': 'Tucson, AZ', 'kind': 'city'}
SETUP = """() => {
  window.input=document.querySelector('#composer');
  window.owner='';window.canvasOwner=()=>owner;window.loadAuth=async()=>{};
  window.chatImagePicker=null;window.chatImageDraftLimit=2000;window.chatImageReferenceLimit=4;
  window.listeningRoom=null;
  window.popupEntry=(_kind,text)=>document.querySelector('#notice').textContent=text;
  window.command=()=>{throw Error('Picker started a build or sent a chat')};
  window.api=async(path,body,signal)=>{
    if(body!==undefined)throw Error('Expected a discovery GET');
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
        page.on('pageerror', lambda error: errors.append(str(error)))

        def route(handler):
            request = handler.request
            requests.append((request.method, request.url))
            parsed = urlsplit(request.url)
            query = parse_qs(parsed.query, keep_blank_values=True)
            if parsed.path == '/':
                handler.fulfill(content_type='text/html', body=HTML)
            elif parsed.path == '/api/canvas-data.php':
                action = query.get('action', [''])[0]
                if action == 'inventory':
                    body = {'ok': True, 'products': PRODUCTS, 'max_sources': 4}
                elif action == 'places':
                    place_id = query.get('id', [None])[0]
                    lookup = query.get('q', [''])[0].lower()
                    if place_id:
                        items = [item for item in (PHOENIX, TUCSON, VENUE) if item['id'] == place_id]
                    elif lookup:
                        items = [item for item in (PHOENIX, TUCSON, VENUE) if lookup in item['title'].lower()]
                    else:
                        items = [VENUE]
                    body = {'ok': True, 'items': items}
                else:
                    handler.abort()
                    return
                handler.fulfill(content_type='application/json', body=json.dumps(body))
            else:
                handler.abort()

        page.route('**/*', route)
        page.goto('https://data.test/')
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-resource-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-data-picker.js').read_text())
        page.evaluate(SETUP)
        page.add_script_tag(content=COMPOSER + SUBMIT + DESIGN +
                            "bindChatResourcePicker();document.querySelector('#chat').onsubmit=submitAgentChat;")

        composer = page.get_by_label('Prompt')
        picker = page.get_by_role('dialog', name='Choose live data')
        search = picker.get_by_role('searchbox', name='Search for a city or venue')
        source = picker.get_by_role('combobox', name='Data source', exact=True)
        place = picker.get_by_role('combobox', name='City or venue', exact=True)
        name = picker.get_by_label('Source name')
        add = picker.get_by_role('button', name='Add source')
        attach = picker.get_by_role('button', name='Attach sources')

        def activate(control):
            control.tap() if width == 390 else control.click()

        def open_picker(draft):
            composer.fill(draft)
            composer.press('Enter')
            expect(picker).to_be_visible()
            expect(source).to_be_visible()
            expect(place.locator('option')).to_have_count(3)

        def find(query, canonical_id):
            search.fill(query)
            activate(picker.get_by_role('button', name='Search', exact=True))
            expect(place.locator(f'option[value="{canonical_id}"]')).to_have_count(1)
            place.select_option(canonical_id)

        composer.fill('/data')
        page.wait_for_timeout(400)
        expect(picker).not_to_be_visible()
        assert len(requests) == 1

        open_picker('/Data')
        expect(source.locator('option')).to_have_count(3)
        expect(picker.get_by_text('Recent conditions <b>observed</b>.', exact=True)).to_be_visible()
        assert not picker.locator('b').count()
        activate(picker.get_by_text('Available fields', exact=True))
        expect(picker.get_by_text('temperature_f', exact=True)).to_be_visible()
        find('Phoenix', PHOENIX['id'])
        expect(name).to_have_value('phoenix-az-current')
        name.fill('phoenix-live')
        activate(add)
        find('Tucson', TUCSON['id'])
        source.select_option('weather.current')
        name.fill('phoenix-live')
        activate(add)
        expect(picker.get_by_text('Use a different name for each source.')).to_be_visible()
        name.fill('tucson-live')
        activate(add)
        source.select_option('maps.points')
        name.fill('tucson-map')
        activate(add)
        expect(picker.get_by_text('3 of 4 sources')).to_be_visible()

        scope_option = place.locator('option[value="published-shows"]')
        expect(scope_option).to_have_count(0)
        source.select_option('weather.current')
        expect(scope_option).to_have_text(VENUE_SCOPE['title'])
        place.select_option(VENUE_SCOPE['id'])
        source.select_option('maps.points')
        expect(scope_option).to_have_count(0)
        expect(place).to_have_value('')
        expect(add).to_be_disabled()
        expect(place.locator(f'option[value="{TUCSON["id"]}"]')).to_have_count(1)
        source.select_option('weather.current')
        place.select_option(VENUE_SCOPE['id'])
        search.fill('Missing town')
        activate(picker.get_by_role('button', name='Search', exact=True))
        expect(picker.get_by_text('No matching public cities or venues.')).to_be_visible()
        expect(place).to_have_value(VENUE_SCOPE['id'])
        name.fill('venue-weather')
        activate(add)
        expect(picker.get_by_text('4 of 4 sources')).to_be_visible()
        expect(add).to_be_disabled()
        activate(attach)
        expect(composer).to_be_focused()
        expected_bindings = ('{data:phoenix-live:weather.current:city-0455000} '
                             '{data:tucson-live:weather.current:city-0477000} '
                             '{data:tucson-map:maps.points:city-0477000} '
                             '{data:venue-weather:weather.current:published-shows}')
        expect(composer).to_have_value(expected_bindings)
        bindings = page.evaluate('chatDraftResourceInputs(input.value).artist_inputs.data_sources')
        assert bindings == [
            {'alias': 'phoenix-live', 'product': 'weather.current', 'location_id': PHOENIX['id']},
            {'alias': 'tucson-live', 'product': 'weather.current', 'location_id': TUCSON['id']},
            {'alias': 'tucson-map', 'product': 'maps.points', 'location_id': TUCSON['id']},
            {'alias': 'venue-weather', 'product': 'weather.current', 'location_id': VENUE_SCOPE['id']},
        ]
        assert page.evaluate('canvasDesignBrief(input.value)===""&&input.maxLength>2000')

        page.evaluate("(sources)=>{owner='1';listeningRoom={id:8,artist_inputs:{data_sources:sources}}}", bindings)
        open_picker('/edit Restyle {me:my_preferences} {music-track:song} /data')
        selected = picker.get_by_role('list', name='Selected data sources')
        expect(selected.get_by_text('Map points · Tucson, AZ')).to_be_visible()
        expect(selected.get_by_text('Current conditions · ' + VENUE_SCOPE['title'])).to_be_visible()
        expect(attach).to_be_enabled()
        for item in bindings:
            activate(picker.get_by_role('button', name='Remove ' + item['alias'], exact=True))
        activate(attach)
        expect(composer).to_have_value('/edit Restyle {me:my_preferences} {music-track:song} {data-clear}')
        assert page.evaluate('chatDraftResourceInputs(input.value).artist_inputs') == {
            'me_tools': ['my_preferences'], 'data_sources': []}

        page.evaluate("""() => {
          listeningRoom.artist_inputs.data_sources=[{alias:'old-venue',product:'weather.current',location_id:'show-retired'}];
        }""")
        open_picker('/edit /data')
        expect(picker.get_by_text('Current conditions · show-retired · Unavailable')).to_be_visible()
        expect(attach).to_be_disabled()
        activate(picker.get_by_role('button', name='Remove old-venue'))
        find('Phoenix', PHOENIX['id'])
        activate(add)
        page.evaluate("listeningRoom={id:9}")
        activate(attach)
        expect(composer).to_have_value('/edit /data')
        assert not errors
        assert all(method == 'GET' for method, _ in requests)
        assert any('action=places&id=city-0455000' in url for _, url in requests)
        assert not any('action=places&id=published-shows' in url for _, url in requests)
        assert all(url == 'https://data.test/' or url.startswith('https://data.test/api/canvas-data.php?') for _, url in requests)
        print(f'PASS chromium/{width}: multiple named data sources, product-scoped venues, safe restoration and stale edit protection')
        context.close()
    browser.close()
