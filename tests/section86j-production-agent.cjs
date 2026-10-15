'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-production-advisor.js'),'utf8');
const window={};
vm.runInNewContext(source,{window,console});
const module=window.SFStemProductionAdvisor;
assert.equal(typeof module.render,'function');
assert.equal(typeof module.bind,'function');
const html=module.render({track_id:'song-1'});
assert.ok(html.includes('data-j-inspect')&&html.includes('data-j-chat'),
 'Advisor adds inspect and Chat actions without a competing chat client');
assert.ok(!html.includes('APPROVE')&&!html.includes('execute'),
 'Production overview does not bypass action approval');
assert.ok(html.includes('Agent Brain Production Assistant')&&html.includes('data-j-results'),
 'Useful read-only evidence panel exists inside original studio');
const studio=fs.readFileSync(path.join(__dirname,'../admin/assets/stem-studio.js'),'utf8');
assert.ok(studio.includes('SFStemProductionAdvisor.render(p)')&&
 studio.includes('SFStemProductionAdvisor.bind(root,this)'),
 'Integrated render and bindings are located in current Stem Studio');
assert.ok(source.includes("editor.querySelector('[data-auto-propose]')")&&
 source.includes('launch.click()')&&source.includes('current.revision!==studio.project.revision'),
 'Exact review leverages existing governed proposal with revision guard');
assert.ok(!source.includes("action:'execute'")&&!source.includes("action:'approve'"),
 'Production assistant cannot approve or execute on the owner’s behalf');
assert.ok(source.includes('textContent=row.evidence')&&source.includes('textContent=row.recommendation'),
 'Agent-derived text does not flow into unsafe HTML');
console.log('86J browser review/approval integration: 7 checks green');
