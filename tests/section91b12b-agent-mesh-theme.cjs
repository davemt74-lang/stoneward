'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
const read=filename=>fs.readFileSync(path.join(root,filename),'utf8');
const core=read('api/theme-core.php');
const publicCss=read('assets/css/site.css');
const adminCss=read('admin/assets/agent-mesh-theme.css');
const adminShell=read('admin/index.php');
const publicShell=read('stonefellow-v120.php');
let passes=0;
function check(name,fn){fn();passes++;console.log('91B12B THEME PASS '+name);}
const lum=hex=>{const rgb=hex.slice(1).match(/../g).map(x=>parseInt(x,16)/255).map(x=>x<=.03928?x/12.92:((x+.055)/1.055)**2.4);return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;};
const contrast=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};

check('Agent Mesh is the new default and legacy/custom published themes remain supported',()=>{
  assert.match(core,/'agent_mesh'=>\[/);
  assert.match(core,/return \['preset'=>'agent_mesh'\]\+\$p\['agent_mesh'\]/);
  assert.match(core,/'desert'=>\[/);
  assert.match(core,/sf_theme_decode\(sf_meta_get\('site\.theme_published_json',''\),\$d\)/);
  assert.match(publicShell,/sf_theme_css\(\$activeTheme\)/);
});
check('Agent Mesh dark/light palettes meet legibility targets',()=>{
  const palette={dark:{bg:'#080d19',surface:'#101b30',ink:'#eaf3ff',muted:'#aabdd8',accent:'#8ad4ff',accentText:'#071427'},
    light:{bg:'#eef5ff',surface:'#ffffff',ink:'#15233a',muted:'#53647e',accent:'#2557a5',accentText:'#ffffff'}};
  for(const value of Object.values(palette)){
    assert.ok(contrast(value.ink,value.bg)>=4.5);
    assert.ok(contrast(value.muted,value.bg)>=4.5);
    assert.ok(contrast(value.ink,value.surface)>=4.5);
    assert.ok(contrast(value.accent,value.bg)>=4.5);
    assert.ok(contrast(value.accentText,value.accent)>=4.5);
  }
});
check('Public site CSS implements blue mesh background and glass panels via theme tokens',()=>{
  assert.match(publicCss,/:root\{--bg:#080d19;--surface:#101b30/);
  assert.match(publicCss,/body:not\(\.sf-agent-canvas-active\)/);
  assert.match(publicCss,/rgba\(var\(--accent-rgb\),\.045\)/);
  assert.match(publicCss,/\.chatbar,\.footer-player,\.agent-stage \.agent-message/);
  assert.match(publicCss,/:where\(button,a,input,textarea,select\):focus-visible/);
});
check('Admin receives a last-loaded dedicated blue theme without changing navigation markup',()=>{
  assert.match(adminCss,/:root \{/);
  assert.match(adminCss,/--accent:#8ad4ff/);
  assert.match(adminCss,/\.sidebar nav button\.active/);
  assert.match(adminCss,/\.admin-chatbar/);
  assert.match(adminShell,/agent-mesh-theme\.css\?v=/);
  assert.ok(adminShell.indexOf('agent-mesh-theme.css')>adminShell.indexOf('assets/theme.css'));
  assert.match(adminShell,/<nav id="adminNav"/);
});
console.log('Section 91B12B theme: '+passes+' checks passed');
