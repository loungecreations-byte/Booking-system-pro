import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Award, Compass, Lock, Sparkles } from 'lucide-react';

const mount = document.getElementById('bsp-gamification-progress');
const config = { restUrl: mount?.dataset.restUrl, nonce: mount?.dataset.restNonce };

async function request(path = '', options = {}) {
  const response = await fetch(`${config.restUrl || '/wp-json/bsp/v1/me/progress'}${path}`, {
    credentials: 'same-origin',
    ...options,
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '', ...(options.headers || {}) },
  });
  if (!response.ok) throw new Error('Voortgang kon niet worden geladen.');
  return response.json();
}

function LevelProgress({ progress }) {
  return <section className="bsp-progress-level" aria-labelledby="bsp-level-name">
    <div className="bsp-progress-level__icon"><Compass aria-hidden="true" /></div>
    <div className="bsp-progress-level__body">
      <span>Level {progress.number}</span>
      <h3 id="bsp-level-name">{progress.name}</h3>
      <progress className="bsp-progress-meter" value={progress.progress} max="100">{progress.progress}%</progress>
      <p>{progress.next_xp ? `${progress.xp} XP · nog ${Math.max(0, progress.next_xp - progress.xp)} XP tot ${progress.next_name}` : `${progress.xp} XP · hoogste level bereikt`}</p>
    </div>
  </section>;
}

function BadgeGallery({ badges }) {
  const earnedCount=badges.filter(badge=>Boolean(badge.awarded_at)&&!badge.revoked_at).length;
  return <section className="bsp-progress-section"><div className="bsp-section-heading"><h3>Badges</h3><span>{earnedCount} van {badges.length} behaald</span></div><div className="bsp-badge-grid">
    {badges.map((badge) => { const earned = Boolean(badge.awarded_at) && !badge.revoked_at; return <article className={`bsp-badge ${earned ? 'is-earned' : ''}`} key={badge.slug}>
      <span className="bsp-badge__icon">{earned ? <Award aria-hidden="true" /> : <Lock aria-hidden="true" />}</span>
      <div><strong>{badge.title}</strong><p>{badge.description}</p><small>{earned ? `Behaald op ${new Date(`${badge.awarded_at}Z`).toLocaleDateString('nl-NL')}` : badge.category}</small></div>
    </article>; })}
  </div></section>;
}

function ActivityFeed({ events }) {
  return <section className="bsp-progress-section"><h3>Recente XP</h3>{events.length ? <ol className="bsp-xp-feed">{events.map((event) => <li key={event.id}><span><Sparkles aria-hidden="true" /></span><div><strong>{eventLabel(event.event_type)}</strong><small>{new Date(`${event.occurred_at}Z`).toLocaleString('nl-NL')}</small></div><b>{event.xp_delta > 0 ? '+' : ''}{event.xp_delta} XP</b></li>)}</ol> : <p className="bsp-progress-empty">Je eerste prestaties verschijnen hier.</p>}</section>;
}

function PrivacySettings({ privacy, onChange }) {
  return <section className="bsp-progress-section"><h3>Privacy</h3><label className="bsp-progress-toggle"><input type="checkbox" checked={Boolean(privacy.opt_in)} onChange={(e) => onChange({ ...privacy, opt_in: e.target.checked })} /><span><strong>Voortgang bijhouden</strong><small>Gebruik geldige platformacties voor XP en badges.</small></span></label><label className="bsp-progress-toggle"><input type="checkbox" checked={Boolean(privacy.public)} onChange={(e) => onChange({ ...privacy, public: e.target.checked })} /><span><strong>Publiek delen toestaan</strong><small>Er wordt nog geen publiek profiel gepubliceerd.</small></span></label></section>;
}

function CollectionBook() {
  const [collection,setCollection]=useState(null); const [sets,setSets]=useState([]); const [type,setType]=useState('all');
  useEffect(()=>{const url=(config.restUrl||'').replace('/progress','/collectibles');const setsUrl=(config.restUrl||'').replace('/progress','/collection-sets');Promise.all([fetch(url,{credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce||''}}).then(r=>r.ok?r.json():Promise.reject()),fetch(setsUrl,{credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce||''}}).then(r=>r.ok?r.json():{sets:[]})]).then(([data,setData])=>{setCollection(data);setSets(setData.sets||[]);data.items.filter(i=>i.unlocked&&!i.seen_at).forEach(i=>fetch(`${url}/${i.id}/seen`,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce||''}}));}).catch(()=>setCollection({items:[],summary:{unlocked:0,total:0,percent:0}}));},[]);
  if(!collection)return <section className="bsp-progress-section"><h3>Collectieboek</h3><p className="bsp-progress-loading">Collectie laden...</p></section>;
  const types=['all',...new Set(collection.items.map(i=>i.type))]; const items=type==='all'?collection.items:collection.items.filter(i=>i.type===type);
  return <section className="bsp-progress-section bsp-collection"><div className="bsp-collection__heading"><div><h3>Collectieboek</h3><p>{collection.summary.unlocked} van {collection.summary.total} gevonden</p></div><strong>{collection.summary.percent}%</strong></div>{sets.map(set=><article className="bsp-collection-set" key={set.id}><div><small>Collectieset</small><strong>{set.title}</strong><p>{set.unlocked} van {set.total} gevonden · {set.xp_reward} XP bij voltooiing</p></div><progress value={set.unlocked} max={set.total}>{set.unlocked}/{set.total}</progress></article>)}<div className="bsp-collection__filters" role="group" aria-label="Collectie filter">{types.map(t=><button type="button" className={type===t?'is-active':''} onClick={()=>setType(t)} key={t}>{t==='all'?'Alles':typeLabel(t)}</button>)}</div><div className="bsp-collection__grid">{items.map(item=><article className={`bsp-collectible rarity-${item.rarity} ${item.unlocked?'is-unlocked':'is-locked'} ${item.unlocked&&!item.seen_at?'is-new':''}`} key={item.id}>{item.image?<img src={item.image} alt="" loading="lazy"/>:<span className="bsp-collectible__placeholder"><Lock aria-hidden="true"/></span>}<div><small>{item.rarity} · {typeLabel(item.type)}</small><strong>{item.title}</strong><p>{item.description}</p>{item.unlocked&&item.unlocked_at?<time>{new Date(`${item.unlocked_at}Z`).toLocaleDateString('nl-NL')}</time>:null}</div></article>)}</div></section>;
}
function typeLabel(type){return ({person:'Persoon',building:'Gebouw',artwork:'Kunstwerk',symbol:'Symbool',object:'Voorwerp',animal:'Dier'})[type]||type;}

function eventLabel(type) { return ({ 'booking.payment_completed':'Betaalde boeking','route.completed':'Route voltooid','audio_tour.completed':'Audiotour voltooid','qr.checkpoint_verified':'Routepunt ontdekt','ticket.attendance_confirmed':'Deelname bevestigd','review.verified':'Review geverifieerd','badge.awarded':'Badge behaald','event.reversed':'XP-correctie','admin.adjustment':'Beheercorrectie' })[type] || 'Voortgang bijgewerkt'; }

function ProgressOverview() {
  const [data, setData] = useState(null); const [error, setError] = useState('');
  useEffect(() => { request().then(setData).catch((e) => setError(e.message)); }, []);
  const updatePrivacy = (privacy) => { setData((current) => ({ ...current, privacy })); request('/privacy', { method:'PATCH', body:JSON.stringify(privacy) }).catch(() => setError('Privacyinstellingen konden niet worden opgeslagen.')); };
  if (error) return <div className="bsp-progress-error" role="alert">{error}</div>;
  if (!data) return <div className="bsp-progress-loading" aria-live="polite">Voortgang laden...</div>;
  return <div className="bsp-progress-app"><LevelProgress progress={data.progress} /><BadgeGallery badges={data.badges || []} /><CollectionBook/><div className="bsp-progress-columns"><div><ActivityFeed events={data.events || []} /></div><div><PrivacySettings privacy={data.privacy || {}} onChange={updatePrivacy} /></div></div></div>;
}

if (mount) createRoot(mount).render(<ProgressOverview />);

export { ProgressOverview, LevelProgress, BadgeGallery, ActivityFeed, PrivacySettings, CollectionBook };
