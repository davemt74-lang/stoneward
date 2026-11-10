"""Exercise /music through the production composer and canvas request handlers."""
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BASELINE = ROOT / 'tests/section91b23a-listening-room-browser.py'
source = BASELINE.read_text()
# Reuse the normal canvas bootstrap, then run only the music-specific checks.
bootstrap = source[:source.index('        check(not history_button.is_visible()')]
bootstrap = bootstrap.replace('widths = (375, 900, 1440)', 'widths = (375, 1440)')

focused = r'''
        page.add_style_tag(content=(ROOT / 'assets/css/canvas-music-picker.css').read_text())
        page.add_script_tag(content=(ROOT / 'assets/js/canvas-music-picker.js').read_text())
        page.evaluate(''' + "'''" + r'''() => {
          const previousApi=window.api;
          const ids=['song-a','song-b','chorus-one','song-c',...Array.from({length:40},(_,index)=>'bulk-'+index)];
          window.musicFixture={reads:[],posts:[],ids,nextId:100};
          musicFixture.catalog={tracks:[...ids.map((track_id,index)=>({track_id,
            title:track_id==='song-b'?'<b>Blue song</b>':track_id,duration_seconds:120+index,
            release_ids:['song-a','song-b','chorus-one'].includes(track_id)?['alpha']:[],
            source_available:true,mime:'audio/mpeg',source_url:'https://egress.test/untrusted-audio'})),
            {track_id:'unavailable',title:'Unavailable song',source_available:false,duration_seconds:0,release_ids:['alpha']}],
            releases:[{release_id:'alpha',title:'Alpha <b>album</b>',track_ids:['song-b','song-a','unavailable','chorus-one']},
              {release_id:'beta',title:'Beta album',track_ids:['chorus-one','song-c']},
              {release_id:'empty',title:'Unavailable album',track_ids:['unavailable']},
              {release_id:'bulk',title:'Whole collection',track_ids:ids.slice(4)}]};
          window.api=async(path,body,signal)=>{
            if(path!=='canvas-audio.php?action=catalog')return previousApi(path,body,signal);
            musicFixture.reads.push({path,signal});
            if(musicFixture.failRead){musicFixture.failRead=false;throw Error('Music could not load.');}
            if(musicFixture.holdRead){musicFixture.holdRead=false;await new Promise(resolve=>musicFixture.releaseRead=resolve);}
            return JSON.parse(JSON.stringify(musicFixture.catalog));
          };
          const previousPost=window.secureJsonPost;
          window.secureJsonPost=async(path,body,unused,signal)=>{
            if(path!=='agent-scene.php')return previousPost(path,body,unused,signal);
            if(body.action==='image_preflight')return {ok:true,preflight:{requested_count:null}};
            musicFixture.posts.push(body);
            const inherited=listeningRoom?.music_inputs;
            return {ok:true,scene:{id:++musicFixture.nextId,canvas_kind:'custom',track_id:'',title:'Music canvas',
              html:'<h1>Authored music canvas</h1>',css:'h1{color:linen}',
              ...(body.music_inputs||inherited?{music_inputs:body.music_inputs||inherited}:{})},design_source:'model'};
          };
          st.auth={authenticated:true,user:{id:4}};syncAuthMenu();bindChatMusicPicker();
        }''' + "'''" + r''')
        composer = page.locator('#composer-input')
        picker = page.get_by_role('dialog', name='Choose canvas music', exact=True)
        composer.fill('/music')
        composer.press_sequentially('-all', delay=30)
        expect(composer).to_have_value("Let's build {music-all}")
        check(not picker.is_visible() and page.evaluate('musicFixture.reads.length===0&&musicFixture.posts.length===0'),
              prefix + 'quickly typing /music-all attaches the full catalog without opening /music or starting a build')
        composer.fill("Let's build an interface /music-all")
        composer.press('Enter')
        page.wait_for_function('musicFixture.posts.length===1&&!listeningRoomRequest')
        check(page.evaluate('musicFixture.posts[0].music_inputs.mode==="all"&&musicFixture.posts[0].brief.includes("{music-all}")&&'
                            'musicFixture.posts[0].track_id===""'),
              prefix + 'an inline full-catalog command sends an all-mode selection through the normal build request')
        composer.fill('')
        composer.press_sequentially('/songs')
        expect(picker.get_by_role('tab', name='Songs', exact=True)).to_have_attribute('aria-selected', 'true')
        picker.get_by_role('checkbox', name='song-a', exact=True).check()
        picker.get_by_role('button', name='Add songs', exact=True).click()
        expect(composer).to_have_value("Let's build {music-track:song-a}")

        composer.fill('')
        composer.press_sequentially('/albums')
        expect(picker.get_by_role('tab', name='Albums', exact=True)).to_have_attribute('aria-selected', 'true')
        picker.get_by_role('checkbox', name='Alpha <b>album</b>', exact=True).check()
        picker.get_by_role('button', name='Add songs', exact=True).click()
        expect(composer).to_have_value("Let's build {music-track:song-b} {music-track:song-a} {music-track:chorus-one}")
        check(page.evaluate('musicFixture.posts.length===1&&played.length===0'),
              prefix + 'typing the direct aliases opens the requested tab and attaches ordered tracks without submitting or playing')

        composer.fill('/edit use /ALBUMS')
        composer.press('Enter')
        expect(picker.get_by_role('tab', name='Albums', exact=True)).to_have_attribute('aria-selected', 'true')
        picker.get_by_role('button', name='Cancel', exact=True).click()
        expect(composer).to_have_value('/edit use /ALBUMS')

        menu_markup = re.search(r'<nav id="chatQuickMenu"[\s\S]+?</nav>', SHELL).group(0)
        menu_markup = re.sub(r'<\?php[^\n]*\?>', '', menu_markup)
        menu_button = re.search(r'<button id="chatQuickButton"[^>]*>[^<]*</button>', SHELL).group(0)
        page.evaluate(''' + "'''" + r'''([markup, button]) => {
          form.insertAdjacentHTML('beforebegin', markup);
          form.insertAdjacentHTML('afterbegin', button);
          window.quickMenu=document.getElementById('chatQuickMenu');
          window.quickButton=document.getElementById('chatQuickButton');
        }''' + "'''" + r''', [menu_markup, menu_button])
        page.add_script_tag(content=block('  function closeQuickMenu(', '  function handleQuickAction('))
        page.evaluate('bindChatCommandMenu();quickButton.onclick=openQuickMenu')
        composer.fill("Let's build a canvas with /songs in this design")
        composer.press('Enter')
        expect(picker.get_by_role('tab', name='Songs', exact=True)).to_have_attribute('aria-selected', 'true')
        picker.get_by_role('button', name='Cancel', exact=True).click()
        page.get_by_role('button', name='Open slash commands').click()
        command_search = page.get_by_role('searchbox', name='Search slash commands')
        command_search.fill('/albums')
        command_search.press('Enter')
        expect(picker.get_by_role('tab', name='Albums', exact=True)).to_have_attribute('aria-selected', 'true')
        expect(composer).to_have_value("Let's build a canvas with /albums in this design")
        picker.get_by_role('checkbox', name='Alpha <b>album</b>', exact=True).check()
        picker.get_by_role('button', name='Add songs', exact=True).click()
        expect(composer).to_have_value("Let's build a canvas with {music-track:song-b} {music-track:song-a} {music-track:chorus-one} in this design")
        check(page.evaluate('musicFixture.posts.length===1&&played.length===0'),
              prefix + 'choosing Albums replaces a canceled Songs shortcut and retains the rest of the draft')

        composer.fill('/music')
        composer.press('Enter')
        expect(picker).to_be_visible()
        expect(picker.get_by_role('checkbox', name='Unavailable song', exact=True)).to_be_disabled()
        check(picker.locator('b').count() == 0 and picker.get_by_text('Hosted MP3 unavailable').is_visible(),
              prefix + 'song titles remain plain text and unavailable MP3s have disabled controls')
        songs = picker.get_by_role('tab', name='Songs', exact=True)
        songs.focus()
        songs.press('ArrowRight')
        expect(picker.get_by_role('tab', name='Albums', exact=True)).to_have_attribute('aria-selected', 'true')
        picker.get_by_role('checkbox', name='Alpha <b>album</b>', exact=True).check()
        beta = picker.get_by_role('checkbox', name='Beta album', exact=True)
        check(beta.evaluate('input=>input.indeterminate'), prefix + 'an overlapping album shows a partial selection')
        beta.check()
        expect(picker.get_by_role('checkbox', name='Unavailable album', exact=True)).to_be_disabled()
        picker.get_by_role('checkbox', name='Whole collection', exact=True).check()
        expect(picker.locator('.sf-canvas-music-count')).to_have_text('44 songs selected')
        picker.get_by_role('button', name='Add songs', exact=True).click()
        expect(picker).not_to_be_visible()
        chosen = page.evaluate('chatDraftMusicInputs(input.value)')
        check(chosen['track_ids'] == ['song-b', 'song-a', 'chorus-one', 'song-c'] + [f'bulk-{i}' for i in range(40)] and
              composer.evaluate('input=>input.maxLength>input.value.length+200') and
              page.evaluate('musicFixture.posts.length===1'),
              prefix + 'album membership keeps release order, deduplicates overlaps and supports more than four songs')
        composer.fill(composer.input_value() + ' Make a board for this music.')
        composer.press('Enter')
        page.wait_for_function('musicFixture.posts.length===2&&!listeningRoomRequest')
        check(page.evaluate('musicFixture.posts[1].music_inputs.track_ids.length===44&&musicFixture.posts[1].track_id===""&&'
                            '!musicFixture.posts[1].lyric_section&&audio.paused&&played.length===0'),
              prefix + 'music IDs reach the request without lyric intent from chorus-like IDs or starting playback')

        image_token = '{image-' + 'c1' * 16 + '}'
        draft = '/edit Keep the layout ' + image_token + ' {music-track:song-a} using /music'
        composer.fill(draft)
        composer.press('Enter')
        expect(picker).to_be_visible()
        picker.get_by_role('checkbox', name='song-a', exact=True).uncheck()
        picker.get_by_role('checkbox', name='<b>Blue song</b>', exact=True).check()
        picker.get_by_role('button', name='Add songs', exact=True).click()
        check(image_token in composer.input_value() and '{music-track:song-a}' not in composer.input_value() and
              composer.input_value().count('{music-track:song-b}') == 1,
              prefix + 'editing a selection replaces prior music tokens while preserving image bindings and design text')
        composer.press('Enter')
        page.wait_for_function('musicFixture.posts.length===3&&!listeningRoomRequest')
        check(page.evaluate('musicFixture.posts[2].previous_id===102&&JSON.stringify(musicFixture.posts[2].music_inputs)===' +
                            'JSON.stringify({mode:"selected",track_ids:["song-b"]})'),
              prefix + 'an edit carries its explicit replacement music selection')
        composer.fill('/edit Make the spacing wider')
        composer.press('Enter')
        page.wait_for_function('musicFixture.posts.length===4&&!listeningRoomRequest')
        check(page.evaluate('!Object.hasOwn(musicFixture.posts[3],"music_inputs")&&listeningRoom.music_inputs.track_ids[0]==="song-b"'),
              prefix + 'an edit without music tokens leaves selection inheritance to the server')
        composer.fill('/edit /music')
        composer.press('Enter')
        expect(picker.get_by_role('checkbox', name='<b>Blue song</b>', exact=True)).to_be_checked()
        expect(picker.get_by_role('checkbox', name='song-a', exact=True)).not_to_be_checked()
        check(picker.locator('.sf-canvas-music-count').inner_text() == '1 song selected',
              prefix + 'an edit without draft bindings preselects the saved canvas music')
        picker.get_by_role('button', name='Cancel', exact=True).click()
        page.evaluate(''' + "'''" + r'''() => {
          listeningRoom.music_inputs={mode:'all'};
          musicFixture.catalog.tracks.push({track_id:'new-live-song',title:'New live song',source_available:true,
            duration_seconds:91,release_ids:[],mime:'audio/mpeg'});
        }''' + "'''" + r''')
        composer.fill('/edit /music')
        composer.press('Enter')
        expect(picker.get_by_role('checkbox', name='New live song', exact=True)).to_be_checked()
        expect(picker.locator('.sf-canvas-music-count')).to_have_text('45 songs selected')
        picker.get_by_role('checkbox', name='New live song', exact=True).uncheck()
        picker.get_by_role('button', name='Add songs', exact=True).click()
        composer.press('Enter')
        page.wait_for_function('musicFixture.posts.length===5&&!listeningRoomRequest')
        check(page.evaluate('musicFixture.posts[4].music_inputs.mode==="selected"&&musicFixture.posts[4].music_inputs.track_ids.length===44&&'
                            '!musicFixture.posts[4].music_inputs.track_ids.includes("new-live-song")'),
              prefix + 'all-mode edits start from the fresh catalog and can remove one song as an explicit replacement')
        composer.fill('/edit {music-all} /music')
        composer.press('Enter')
        expect(picker.get_by_role('checkbox', name='New live song', exact=True)).to_be_checked()
        expect(picker.locator('.sf-canvas-music-count')).to_have_text('45 songs selected')
        page.screenshot(path=f'/tmp/sf284-music-picker-{width}.png')
        picker.get_by_role('button', name='Add songs', exact=True).click()
        check(page.evaluate('chatDraftMusicInputs(input.value).mode==="all"&&!input.value.includes("{music-track:")&&musicFixture.posts.length===5'),
              prefix + 'an unchanged all-catalog draft stays dynamic instead of freezing a list of IDs')

        canceled = "Let's build an orbit /music and preserve " + image_token
        composer.fill(canceled)
        expect(picker).to_be_visible()
        picker.get_by_role('checkbox', name='song-c', exact=True).check()
        picker.get_by_role('button', name='Cancel', exact=True).click()
        expect(composer).to_have_value(canceled)
        expect(composer).to_be_focused()
        check(page.evaluate('musicFixture.posts.length===5'), prefix + 'Cancel preserves the unsent draft and makes no build request')
        composer.fill("Let's build /music-all as a song explorer")
        expect(composer).to_have_value("Let's build {music-all} as a song explorer")
        check(page.evaluate('musicFixture.posts.length===5'),
              prefix + 'a pasted inline full-catalog command expands in place without inventing a design or submitting it')
        pasted = "Let's build a music wall " + ' '.join('{music-track:' + track_id + '}' for track_id in chosen['track_ids']) + ' ' + image_token
        composer.fill('')
        long_brief = 'Let’s build a detailed canvas. ' + 'Use a brushed metal surface with warm highlights. ' * 35
        page.evaluate('text=>navigator.clipboard.writeText(text)', long_brief)
        composer.press('Control+V')
        expect(composer).to_have_value(long_brief)
        composer.press('End')
        composer.press_sequentially(' Add rounded corners.')
        expect(composer).to_have_value(long_brief + ' Add rounded corners.')
        check(page.evaluate('musicFixture.posts.length===5'), prefix + 'long canvas briefs can be pasted and extended without submitting')
        composer.fill('')
        page.evaluate('text=>navigator.clipboard.writeText(text)', 'x' * 400)
        composer.press('Control+V')
        expect(composer).to_have_value('x' * 320)
        check(composer.evaluate('input=>input.maxLength===320'), prefix + 'ordinary pasted text keeps the existing composer limit')
        composer.fill('')
        page.evaluate('text=>navigator.clipboard.writeText(text)', pasted)
        composer.press('Control+V')
        expect(composer).to_have_value(pasted)
        check(page.evaluate('chatDraftMusicInputs(input.value).track_ids.length===44'),
              prefix + 'a native paste retains a large selection and its image binding without consuming the design allowance')

        page.evaluate('musicFixture.failRead=true')
        composer.fill('/music')
        composer.press('Enter')
        expect(picker.get_by_role('button', name='Try again', exact=True)).to_be_visible()
        picker.get_by_role('button', name='Try again', exact=True).click()
        expect(picker.get_by_role('checkbox', name='song-a', exact=True)).to_be_enabled()
        search = picker.get_by_label('Search music', exact=True)
        search.fill('no matching song')
        expect(picker.get_by_text('No music matches your search.', exact=True)).to_be_visible()
        search.fill('Alpha')
        check(picker.get_by_role('checkbox').count() == 4, prefix + 'search finds songs by album metadata and has a clear empty state')
        search.fill('')
        picker.get_by_role('button', name='Use entire catalog', exact=True).click()
        expect(composer).to_have_value("Let's build {music-all}")
        page.evaluate('musicFixture.holdRead=true')
        composer.fill('/music')
        composer.press('Enter')
        page.wait_for_function('typeof musicFixture.releaseRead==="function"')
        page.evaluate('st.auth.user={id:5};syncAuthMenu();musicFixture.releaseRead()')
        expect(picker).not_to_be_visible()
        expect(composer).to_have_value('/music')
        page.wait_for_function('chatMusicPicker===null')
        check(page.evaluate('musicFixture.reads.at(-1).signal.aborted&&musicFixture.posts.length===5'),
              prefix + 'an account change aborts the picker and ignores a late catalog response')
        page.evaluate('musicFixture.catalog={tracks:[],releases:[]}')
        composer.fill('/music')
        composer.press('Enter')
        expect(picker.get_by_text('No public songs are available yet.', exact=True)).to_be_visible()
        expect(picker.get_by_role('button', name='Add songs', exact=True)).to_be_disabled()
        expect(picker.get_by_role('button', name='Use entire catalog', exact=True)).to_be_disabled()
        check(page.evaluate('chatMusicCommandRange("Let’s build /musical")===null&&chatMusicCommandRange("Let’s build /music-allocation")===null&&'
                            'chatMusicCommandRange("Let’s build /albums-extra")===null&&chatMusicCommandRange("https://example.test/songs")===null') and
              picker.evaluate('dialog=>dialog.scrollWidth<=dialog.clientWidth') and not errors and
              not any('untrusted-audio' in request for request in requests),
              prefix + 'empty inventory, command boundaries and mobile layout stay safe without audio requests')
        page.keyboard.press('Escape')
        expect(composer).to_be_focused()
        context.close()
    browser.close()
print(json.dumps({'checks':len(checks),'widths':widths,'scope':'Production music picker and composer wiring'}))
'''

exec(compile(bootstrap + focused, str(BASELINE), 'exec'), {'__file__': str(BASELINE), '__name__': '__main__'})
