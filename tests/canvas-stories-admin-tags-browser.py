"""Verify the real Story Editor sends and reloads tag selections through its save flow."""
import os
from pathlib import Path
import shutil

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = """() => {
  window.posts = [];
  let story = {
    id: 1, entry_type: 'essay', status: 'draft', title: 'Studio notebook',
    slug: 'studio-notebook', body_text: 'How the arrangement came together.',
    tags: ['studio-notes']
  };
  const savedTags = [['behind-the-song', 'tour-notes'], ['behind-the-song', 'tour-notes'], []];
  window.STONEFELLOW_ADMIN_CONTEXT = {
    esc(value) {
      return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      })[char]);
    },
    say() {},
    async api(path, body) {
      if (path !== 'stories.php') throw Error('Unexpected endpoint');
      if (!body) return {stories: [story], references: {}};
      if (body.action !== 'save') throw Error('Unexpected write');
      window.posts.push(structuredClone(body));
      story = {...body.story, tags: savedTags[window.posts.length - 1]};
      return {story};
    }
  };
}"""


with sync_playwright() as playwright:
    options = {'headless': True, 'args': ['--no-sandbox']}
    executable = os.environ.get('CHROMIUM_BIN') or shutil.which('chromium')
    if executable:
        options['executable_path'] = executable
    browser = playwright.chromium.launch(**options)
    for width in (390, 1440):
        context = browser.new_context(viewport={'width': width, 'height': 850}, has_touch=width == 390)
        try:
            page = context.new_page()
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.set_content('<main id="storytellingPanel"></main>')
            for css in ('admin.css', 'stories.css'):
                page.add_style_tag(content=(ROOT / 'admin/assets' / css).read_text())
            page.evaluate(FIXTURE)
            page.add_script_tag(content=(ROOT / 'admin/assets/stories.js').read_text())
            page.evaluate('SFStoriesAdmin.renderInto()')
            page.locator('[data-story-open="1"]').click()
            editor = page.get_by_role('dialog', name='Edit Story')
            tags = editor.get_by_role('textbox', name='Tags', exact=True)
            save = editor.get_by_role('button', name='Save story', exact=True)
            expect(tags).to_have_value('studio-notes')
            expect(editor.get_by_text('/stories-studio-notes', exact=True)).to_be_visible()
            assert page.evaluate('posts.length') == 0

            tags.fill(' Behind the Song, , Tour Notes ,')
            save.click()
            expect(tags).to_have_value('behind-the-song, tour-notes')
            expect(editor.get_by_text('/stories-behind-the-song', exact=True)).to_be_visible()
            expect(editor.get_by_text('/stories-tour-notes', exact=True)).to_be_visible()
            draft = page.evaluate('posts[0]')
            assert draft['story']['tags'] == ['Behind the Song', 'Tour Notes']
            assert draft['story']['status'] == 'draft' and draft['confirmed'] is False

            editor.get_by_role('combobox', name='Status', exact=True).select_option('published')
            page.once('dialog', lambda dialog: dialog.dismiss())
            save.click()
            assert page.evaluate('posts.length') == 1
            page.once('dialog', lambda dialog: dialog.accept())
            save.click()
            expect(editor.get_by_role('link', name='Preview public story')).to_be_visible()
            publish = page.evaluate('posts[1]')
            assert publish['story']['tags'] == ['behind-the-song', 'tour-notes']
            assert publish['story']['status'] == 'published' and publish['confirmed'] is True

            tags.fill(' , ')
            save.click()
            expect(tags).to_have_value('')
            expect(editor.get_by_text('/stories-tour-notes', exact=True)).to_have_count(0)
            assert page.evaluate('posts[2].story.tags') == []
            assert page.evaluate('posts.length') == 3
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
            assert not errors, errors
            print(f'PASS Chromium/{width}px Story Editor saves tags, confirms publication and clears tags')
        finally:
            context.close()
    browser.close()
