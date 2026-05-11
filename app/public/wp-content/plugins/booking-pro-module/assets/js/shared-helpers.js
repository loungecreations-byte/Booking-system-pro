// Shared prefill helpers for widget / product / planner
(function(){
    if (window.SBDP_SHARED_HELPERS) return;
    const MAX_FUTURE_DAYS = 365;
    function pad2(val){return String(val).padStart(2,'0');}
    function toLocalISO(date){const d=new Date(date.getTime()-date.getTimezoneOffset()*60000);return d.toISOString().split('T')[0];}
    function normalizeDate(raw){if(!raw) return '';const str=String(raw).trim();if(/^\d{4}-\d{2}-\d{2}$/.test(str)) return str;const m=str.match(/^(\d{1,2})[-\/]?(\d{1,2})(?:[-\/]?(\d{2,4}))?$/);if(m){const day=parseInt(m[1],10);const month=parseInt(m[2],10);const year=m[3]?parseInt(m[3].length===2?`20${m[3]}`:m[3],10):new Date().getFullYear();if(day>=1&&day<=31&&month>=1&&month<=12&&year>1900){return `${year}-${pad2(month)}-${pad2(day)}`;}}return '';} 
    function clampCount(val){const parsed=parseInt(val,10);if(!Number.isFinite(parsed)||parsed<=0)return 0;return Math.min(100, parsed);} 
    function canonicalDuration(base){const b=(base||'').toLowerCase();if(['hele-dag','weekend'].includes(b))return b;if(b==='avond')return 'avond';if(b==='ochtend'||b==='3-4u'||b==='34u')return 'ochtend';if(b==='middag'||b==='6u'||b==='5-6u'||b==='56u')return 'middag';return 'hele-dag';}
    function deriveAudience(val){const map={vrienden:'vrienden', familie:'gezin', gemengd:'vrienden', collegas:'collegas', "collega's":'collegas', bedrijf:'collegas', school:'collegas', partner:'partner', romantisch:'partner', solo:'solo'};return map[(val||'').toLowerCase()]||'vrienden';}
    function deriveVibes(prefs, verras){if(verras) return ['verrassend'];const out=[];(prefs||[]).forEach(val=>{const v=(val||'').toLowerCase();if(!v||v==='verras')return;if(!out.includes(v)) out.push(v);if(v==='winkelen'&&!out.includes('shoppen')) out.push('shoppen');if(v==='buitenlucht'&&!out.includes('actief')) out.push('actief');if(v==='verrassing'&&!out.includes('verrassend')) out.push('verrassend');if(v==='food'&&!out.includes('bourgondisch')) out.push('bourgondisch');});return out;}
    window.SBDP_SHARED_HELPERS={pad2,toLocalISO,normalizeDate,clampCount,canonicalDuration,deriveAudience,deriveVibes,MAX_FUTURE_DAYS};
})();