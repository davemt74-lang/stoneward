/* Shared public/Admin speech segmentation. The content determines when speech ends.
 * Split before provider/browser per-utterance limits without dropping the remainder.
 */
(() => {
  'use strict';
  function split(raw,maximum=1400){
    let remaining=String(raw??'').trim();
    const limit=Math.max(80,Math.min(1700,Math.floor(Number(maximum)||1400)));
    const chunks=[];
    while(remaining){
      if(remaining.length<=limit){chunks.push(remaining);break}
      const windowText=remaining.slice(0,limit+1);
      let boundary=0;
      // Prefer a sentence or paragraph boundary in the second half.
      const sentence=/[.!?。]+(?:["'”’)]*)\s+|\n+/g;
      let match;
      while((match=sentence.exec(windowText))!==null){
        const end=match.index+match[0].length;
        if(end>=Math.floor(limit*0.45)&&end<=limit)boundary=end;
      }
      if(!boundary){
        for(let i=limit;i>=Math.floor(limit*0.45);i--){
          if(/\s/.test(remaining[i])){boundary=i+1;break}
        }
      }
      if(!boundary)boundary=limit;
      // Avoid producing a broken surrogate pair on an unusually long word.
      if(boundary<remaining.length&&boundary>0&&/[\uD800-\uDBFF]/.test(remaining[boundary-1])&&/[\uDC00-\uDFFF]/.test(remaining[boundary]))boundary--;
      chunks.push(remaining.slice(0,boundary).trim());
      remaining=remaining.slice(boundary).trimStart();
    }
    return chunks.filter(Boolean);
  }
  window.StonefellowSpeechSegments={split};
})();
