/* Match the host agent to an authored canvas without executing its CSS. */
(() => {
  'use strict';
  const hexColor = /^#[0-9a-f]{6}$/i;
  const clamp = value => Math.max(0, Math.min(255, Math.round(value)));
  const hex = rgb => '#' + rgb.map(value => clamp(value).toString(16).padStart(2, '0')).join('');
  let parser;
  function canonicalColor(value) {
    if (typeof value !== 'string') return null;
    const input = value.trim();
    if (!input || input.length > 100 || /[;{}<>\\]|url\s*\(|var\s*\(/i.test(input) ||
      /^(?:transparent|currentcolor|inherit|initial|unset|revert(?:-layer)?)$/i.test(input)) return null;
    const short = input.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (short) {
      const digits = short[1].length === 3 ? short[1].split('').map(c => c + c).join('') : short[1];
      return [0, 2, 4].map(i => parseInt(digits.slice(i, i + 2), 16));
    }
    // Canvas parses the browser's color vocabulary, never a style or HTML fragment.
    try {
      if (parser === undefined) parser = document.createElement('canvas').getContext('2d');
      if (!parser) return null;
      parser.fillStyle = '#010203'; parser.fillStyle = input;
      const first = parser.fillStyle;
      parser.fillStyle = '#040506'; parser.fillStyle = input;
      if (first !== parser.fillStyle) return null;
      if (hexColor.test(first)) return [0, 2, 4].map(i => parseInt(first.slice(i + 1, i + 3), 16));
      const rgba = first.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)$/);
      if (rgba && (rgba[4] === undefined || Number(rgba[4]) >= .5)) return rgba.slice(1, 4).map(Number).map(clamp);
    } catch (_) { /* Missing canvas support keeps the saved atmosphere. */ }
    return null;
  }
  function luminance(rgb) {
    return rgb.map(value => {
      const v = value / 255;
      return v <= .04045 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4);
    }).reduce((sum, value, i) => sum + value * [.2126, .7152, .0722][i], 0);
  }
  function contrast(a, b) {
    const x = luminance(a), y = luminance(b);
    return (Math.max(x, y) + .05) / (Math.min(x, y) + .05);
  }
  function readable(rgb, surface, minimum) {
    if (contrast(rgb, surface) >= minimum) return rgb;
    const light = [245, 245, 245], dark = [20, 20, 20];
    const target = contrast(light, surface) >= contrast(dark, surface) ? light : dark;
    for (let step = 1; step <= 20; step++) {
      const amount = step / 20;
      const next = rgb.map((value, i) => clamp(value + (target[i] - value) * amount));
      if (contrast(next, surface) >= minimum) return next;
    }
    return target;
  }
  function colors(value) {
    if (typeof value !== 'string') return [];
    // Parse only literals. Ignore URLs, quoted text, comments and CSS functions
    // outside color syntax; their contents must never become palette inputs.
    const clean = value.slice(0, 12000).replace(/\/\*[\s\S]*?\*\/|(?:url|var|env)\([^)]*\)|(['"])(?:\\.|(?!\1)[^\\])*\1/gi, ' ');
    const tokens = clean.match(/#[0-9a-f]{3,8}\b|(?:rgb|hsl)a?\([^)]{1,90}\)|\b[a-z]{3,24}\b/gi) || [];
    const result = [], seen = new Set();
    for (const token of tokens) {
      const rgb = canonicalColor(token);
      if (!rgb) continue;
      const key = hex(rgb);
      if (!seen.has(key)) { seen.add(key); result.push(rgb); }
    }
    return result;
  }
  function cssColors(css) {
    if (typeof css !== 'string') return [];
    const clean = css.slice(0, 12000).replace(/\/\*[\s\S]*?\*\/|(?:url|var|env)\([^)]*\)|(['"])(?:\\.|(?!\1)[^\\])*\1/gi, ' ');
    const result = [];
    for (const declaration of clean.matchAll(/([\w-]+)\s*:\s*([^;{}]+)(?=[;}])/g)) {
      if (/^(?:--[\w-]+|color|background(?:-color|-image)?|(?:border|outline)(?:-(?:top|right|bottom|left))?(?:-color)?|fill|stroke|(?:box|text)-shadow|(?:accent|caret)-color)$/i.test(declaration[1])) result.push(...colors(declaration[2]));
    }
    return result;
  }
  function preferredImages(scene) {
    if (!Array.isArray(scene?.image_assets)) return [];
    const css = typeof scene.css === 'string' ? scene.css.slice(0, 12000).replace(/\/\*[\s\S]*?\*\/|(['"])(?:\\.|(?!\1)[^\\])*\1/gi, ' ') : '';
    const html = typeof scene.html === 'string' ? scene.html.slice(0, 12000) : '';
    const bindings = value => Array.from(value.matchAll(/--sf-image-((?:ref-)?[a-f0-9]{32})(?![a-z0-9-])/g), match => match[1]);
    const background = [];
    for (const declaration of css.matchAll(/(?:^|[;{])\s*background(?:-image)?\s*:\s*([^;{}]+)/gi)) background.push(...bindings(declaration[1]));
    const used = new Set(bindings(css));
    for (const tag of html.matchAll(/<[a-z][^>]*\bclass="([^"]*)"/gi)) for (const name of tag[1].split(/\s+/)) {
      const match = name.match(/^sf-image-((?:ref-)?[a-f0-9]{32})$/);
      if (match) used.add(match[1]);
    }
    const assets = new Map(scene.image_assets.filter(image => typeof image?.id === 'string').map(image => [image.id, image]));
    return [...new Set([...background, ...used])].filter(id => assets.has(id));
  }
  function imageColors(scene) {
    if (!Array.isArray(scene.image_assets)) return [];
    const assets = new Map(scene.image_assets.map(image => [image?.id, image]));
    for (const id of preferredImages(scene)) {
      const image = assets.get(id);
      const choices = Array.isArray(image?.colors) ? image.colors.flatMap(colors) : [];
      if (choices.length) return choices;
    }
    return [];
  }
  function finish(accent, secondary, surface) {
    const visible = readable(accent, surface, 3);
    const textFor = rgb => contrast([255, 255, 255], rgb) >= contrast([0, 0, 0], rgb) ? '#ffffff' : '#000000';
    return Object.freeze({accent:hex(visible), secondary:hex(readable(secondary, surface, 2)), surface:hex(surface),
      rgb:Object.freeze(visible.map(clamp)), text:textFor(surface), accentText:textFor(visible), border:hex(visible) + '80'});
  }
  function explicit(scene) {
    const supplied = scene?.agent_palette;
    if (supplied && ['accent', 'secondary', 'surface'].every(key => typeof supplied[key] === 'string' && hexColor.test(supplied[key]))) {
      return finish(canonicalColor(supplied.accent), canonicalColor(supplied.secondary), canonicalColor(supplied.surface));
    }
    return null;
  }
  function fromColors(choices) {
    if (!choices.length) return null;
    // Keep neutral scenes neutral. Prefer a colored accent only when supplied.
    const saturation = rgb => (Math.max(...rgb) - Math.min(...rgb)) / 255;
    const sorted = [...choices].sort((a, b) => saturation(b) - saturation(a) || luminance(b) - luminance(a));
    let surface = [...choices].sort((a, b) => luminance(a) - luminance(b))[0];
    if (luminance(surface) > .18) surface = surface.map(value => clamp(value * .25));
    const accent = sorted[0], secondary = sorted.find(rgb => hex(rgb) !== hex(accent)) || accent;
    return finish(accent, secondary, surface);
  }
  function fromArtwork(palette) {
    if (!Array.isArray(palette?.colors)) return null;
    return fromColors(palette.colors.filter(color => typeof color === 'string' && hexColor.test(color)).slice(0, 6).map(canonicalColor));
  }
  function resolve(scene, {includeImageMetadata = true} = {}) {
    if (!scene || typeof scene !== 'object') return null;
    const authored = explicit(scene);
    if (authored) return authored;
    const metadata = includeImageMetadata ? imageColors(scene) : [];
    return fromColors(metadata.length ? metadata : cssColors(scene.css));
  }
  window.SFCanvasAgentTheme = Object.freeze({resolve, explicit, preferredImages, fromArtwork});
})();
