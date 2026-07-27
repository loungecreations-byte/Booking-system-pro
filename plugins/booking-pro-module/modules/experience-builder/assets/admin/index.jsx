import React, { useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";

const TYPES = {
  text: { label: "Tekst", category: "Inhoud" },
  image: { label: "Afbeelding", category: "Media" },
  audio: { label: "Audio", category: "Media" },
  video: { label: "Video", category: "Media" },
  sketchfab: { label: "Sketchfab 3D", category: "Interactief" },
  ai_photo_challenge: { label: "AI Photo Challenge", category: "Interactief" },
  quiz: { label: "Quiz", category: "Interactief" },
  reward: { label: "Beloning", category: "Progressie" },
};

const TEMPLATES = [
  { id: "story_media", label: "Verhaal met media", description: "Tekst, afbeelding, audio en video.", types: ["text", "image", "audio", "video"] },
  { id: "interactive", label: "Interactief hoofdstuk", description: "Verhaal, 3D-model, quiz en beloning.", types: ["text", "sketchfab", "quiz", "reward"] },
  { id: "discovery", label: "AI Discovery", description: "Verhaal, camera-opdracht, quiz en beloning.", types: ["text", "ai_photo_challenge", "quiz", "reward"] },
];

const uuid = () =>
  globalThis.crypto?.randomUUID?.() ||
  "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (char) => {
    const value = (Math.random() * 16) | 0;
    return (char === "x" ? value : (value & 3) | 8).toString(16);
  });

function emptyModule(type) {
  return {
    id: uuid(),
    type,
    version: 1,
    enabled: true,
    title: TYPES[type]?.label || "Module",
    content: type === "text"
      ? { html: "" }
      : type === "sketchfab"
        ? { model_url: "", model_uid: "", introduction: "", instruction: "", fallback_text: "Dit 3D-model kan niet worden geladen." }
        : type === "ai_photo_challenge"
          ? {}
        : type === "quiz"
          ? {}
        : type === "reward"
          ? { title: "Beloning ontgrendeld", message: "Je hebt alle onderdelen voltooid.", xp_amount: 0 }
        : { attachment_id: 0, url: "" },
    settings: type === "sketchfab"
      ? { autostart: false, autorotate: 0, animation_autoplay: true, minimum_view_seconds: 15, required_annotations: [] }
      : type === "ai_photo_challenge"
        ? { source: "chapter_meta" }
      : type === "quiz"
        ? { source: "chapter_meta" }
      : type === "reward"
        ? { event_type: "experience.module_reward" }
      : {},
    conditions: [],
    completion: { mode: type === "sketchfab" ? "manual" : type === "ai_photo_challenge" ? "photo_approved" : type === "quiz" ? "quiz_passed" : type === "reward" ? "server_claim" : "automatic", requirements: [] },
    visibility: { mode: "when_conditions_match" },
    metadata: {},
  };
}

function MediaField({ module, onChange }) {
  const choose = () => {
    if (!window.wp?.media) return;
    const mediaType = module.type === "image" ? "image" : module.type;
    const frame = window.wp.media({
      title: `Selecteer ${TYPES[module.type]?.label.toLowerCase()}`,
      multiple: false,
      library: { type: mediaType },
    });
    frame.on("select", () => {
      const item = frame.state().get("selection").first().toJSON();
      onChange({ ...module.content, attachment_id: Number(item.id || 0), url: item.url || "" });
    });
    frame.open();
  };

  return (
    <div className="sbdp-eb__fields">
      <label>
        <span>Media-URL</span>
        <input
          type="url"
          value={module.content?.url || ""}
          onChange={(event) => onChange({ ...module.content, url: event.target.value })}
          placeholder="https://"
        />
      </label>
      <button type="button" className="button" onClick={choose}>
        Kies uit mediabibliotheek
      </button>
      {(module.type === "audio" || module.type === "video") && (
        <label>
          <span>Transcript</span>
          <textarea
            value={module.content?.transcript || ""}
            onChange={(event) => onChange({ ...module.content, transcript: event.target.value })}
            rows="3"
          />
        </label>
      )}
      {module.type === "image" && (
        <label>
          <span>Alternatieve tekst</span>
          <input
            type="text"
            value={module.content?.alt || ""}
            onChange={(event) => onChange({ ...module.content, alt: event.target.value })}
          />
        </label>
      )}
    </div>
  );
}

function ModuleCard({ module, index, total, previousModules, onPatch, onMove, onDuplicate, onRemove, onDragStart, onDrop }) {
  const [open, setOpen] = useState(true);
  const updateContent = (content) => onPatch({ content });

  return (
    <article
      className={`sbdp-eb__module${module.enabled ? "" : " is-disabled"}`}
      draggable
      onDragStart={onDragStart}
      onDragOver={(event) => event.preventDefault()}
      onDrop={onDrop}
    >
      <header className="sbdp-eb__module-header">
        <button
          type="button"
          className="sbdp-eb__collapse"
          onClick={() => setOpen((value) => !value)}
          aria-expanded={open}
          aria-label={open ? "Module inklappen" : "Module uitklappen"}
        >
          {open ? "▾" : "▸"}
        </button>
        <span className="sbdp-eb__type">{TYPES[module.type]?.label || module.type}</span>
        <input
          className="sbdp-eb__title"
          value={module.title || ""}
          onChange={(event) => onPatch({ title: event.target.value })}
          aria-label="Moduletitel"
        />
        <div className="sbdp-eb__actions">
          <button type="button" onClick={() => onMove(-1)} disabled={index === 0} aria-label="Omhoog verplaatsen">↑</button>
          <button type="button" onClick={() => onMove(1)} disabled={index === total - 1} aria-label="Omlaag verplaatsen">↓</button>
          <button type="button" onClick={onDuplicate} disabled={["ai_photo_challenge", "quiz"].includes(module.type)} title={["ai_photo_challenge", "quiz"].includes(module.type) ? "Dit gekoppelde moduletype is één keer per hoofdstuk toegestaan." : ""}>Dupliceren</button>
          <button type="button" onClick={onRemove}>Verwijderen</button>
        </div>
      </header>
      {open && (
        <div className="sbdp-eb__module-body">
          <label className="sbdp-eb__toggle">
            <input
              type="checkbox"
              checked={module.enabled}
              onChange={(event) => onPatch({ enabled: event.target.checked })}
            />
            Module actief
          </label>
          {module.type === "text" ? (
            <label>
              <span>Tekst</span>
              <textarea
                value={module.content?.html || ""}
                onChange={(event) => updateContent({ html: event.target.value })}
                rows="7"
              />
            </label>
          ) : module.type === "sketchfab" ? (
            <div className="sbdp-eb__fields">
              <label><span>Sketchfab model-URL</span><input type="url" value={module.content?.model_url || ""} onChange={(event) => updateContent({ ...module.content, model_url: event.target.value })} placeholder="https://sketchfab.com/3d-models/..." /></label>
              <label><span>Model UID</span><input type="text" value={module.content?.model_uid || ""} onChange={(event) => updateContent({ ...module.content, model_uid: event.target.value.trim() })} /></label>
              <label><span>Introductie</span><textarea rows="3" value={module.content?.introduction || ""} onChange={(event) => updateContent({ ...module.content, introduction: event.target.value })} /></label>
              <label><span>Opdracht</span><textarea rows="2" value={module.content?.instruction || ""} onChange={(event) => updateContent({ ...module.content, instruction: event.target.value })} /></label>
              <label><span>Minimale kijktijd (seconden)</span><input type="number" min="5" max="600" value={module.settings?.minimum_view_seconds || 15} onChange={(event) => onPatch({ settings: { ...module.settings, minimum_view_seconds: Number(event.target.value) } })} /></label>
              <label><span>Automatisch roteren (-10 tot 10)</span><input type="number" min="-10" max="10" step="0.5" value={module.settings?.autorotate || 0} onChange={(event) => onPatch({ settings: { ...module.settings, autorotate: Number(event.target.value) } })} /></label>
              <label><span>Vereiste annotation-indexen</span><input type="text" value={(module.settings?.required_annotations || []).join(", ")} onChange={(event) => onPatch({ settings: { ...module.settings, required_annotations: event.target.value.split(",").map((value) => Number(value.trim())).filter(Number.isInteger) } })} placeholder="0, 2, 4" /></label>
              <label className="sbdp-eb__toggle"><input type="checkbox" checked={Boolean(module.settings?.autostart)} onChange={(event) => onPatch({ settings: { ...module.settings, autostart: event.target.checked } })} /> Automatisch starten zodra zichtbaar</label>
              <label className="sbdp-eb__toggle"><input type="checkbox" checked={module.settings?.animation_autoplay !== false} onChange={(event) => onPatch({ settings: { ...module.settings, animation_autoplay: event.target.checked } })} /> Animatie automatisch afspelen</label>
            </div>
          ) : module.type === "ai_photo_challenge" ? (
            <div className="sbdp-eb__adapter-notice">
              <strong>Bestaande Discovery Camera gekoppeld</strong>
              <p>Missie, hints, AI-validatie, voorbeeldfoto en rewards beheer je in het paneel ‘AI Discovery Camera’ op deze pagina.</p>
              <a className="button" href="#ddb-photo-challenge">Open camera-instellingen</a>
            </div>
          ) : module.type === "quiz" ? (
            <div className="sbdp-eb__adapter-notice">
              <strong>Bestaande hoofdstukquiz gekoppeld</strong>
              <p>Vragen en antwoorden blijven opgeslagen in de bestaande quizconfiguratie van dit hoofdstuk. In de tour worden antwoorden server-side beoordeeld.</p>
            </div>
          ) : module.type === "reward" ? (
            <div className="sbdp-eb__fields">
              <label><span>Titel</span><input type="text" value={module.content?.title || ""} onChange={(event) => updateContent({ ...module.content, title: event.target.value })} /></label>
              <label><span>Tekst</span><textarea rows="3" value={module.content?.message || ""} onChange={(event) => updateContent({ ...module.content, message: event.target.value })} /></label>
              <label><span>XP-intentie</span><input type="number" min="0" max="500" value={module.content?.xp_amount || 0} onChange={(event) => updateContent({ ...module.content, xp_amount: Number(event.target.value) })} /></label>
              <p>De server beslist definitief of XP, badges of collectibles worden toegekend. Refresh en dubbele requests leveren nooit een dubbele beloning op.</p>
            </div>
          ) : (
            <MediaField module={module} onChange={updateContent} />
          )}
          <details>
            <summary>Geavanceerd</summary>
            <label>
              <span>Completion</span>
              <select
                value={module.completion?.mode || "automatic"}
                onChange={(event) => onPatch({ completion: { ...module.completion, mode: event.target.value } })}
              >
                {!["sketchfab", "ai_photo_challenge", "quiz", "reward"].includes(module.type) && <option value="automatic">Automatisch</option>}
                {!["ai_photo_challenge", "quiz", "reward"].includes(module.type) && <option value="manual">Handmatig</option>}
                {module.type === "ai_photo_challenge" && <option value="photo_approved">Na goedgekeurde foto</option>}
                {module.type === "quiz" && <option value="quiz_passed">Na server-goedgekeurde quiz</option>}
                {module.type === "reward" && <option value="server_claim">Server-side claim</option>}
                {module.type === "sketchfab" && <option value="viewer_ready">Model geladen</option>}
                {module.type === "sketchfab" && <option value="minimum_view_time">Minimale kijktijd</option>}
                {module.type === "sketchfab" && <option value="annotation_opened">Specifieke annotation geopend</option>}
                {module.type === "sketchfab" && <option value="all_required_annotations">Alle vereiste annotations geopend</option>}
              </select>
            </label>
            <div className="sbdp-eb__conditions">
              <div className="sbdp-eb__conditions-head">
                <strong>Zichtbaarheid (alle voorwaarden moeten kloppen)</strong>
                <button
                  type="button"
                  className="button"
                  disabled={previousModules.length === 0}
                  onClick={() => onPatch({
                    conditions: [...(module.conditions || []), {
                      type: "module_completed",
                      module_id: previousModules[0]?.id || "",
                      operator: "is",
                      value: "1",
                    }],
                  })}
                >
                  Voorwaarde toevoegen
                </button>
              </div>
              {(module.conditions || []).map((condition, conditionIndex) => (
                <div className="sbdp-eb__condition" key={`${module.id}-condition-${conditionIndex}`}>
                  <label>
                    <span>Regel</span>
                    <select value={condition.type || "module_completed"} onChange={(event) => {
                      const next = [...module.conditions];
                      next[conditionIndex] = { ...condition, type: event.target.value };
                      onPatch({ conditions: next });
                    }}>
                      <option value="module_completed">Module voltooid</option>
                      <option value="quiz_score_at_least">Minimale quizscore</option>
                      <option value="photo_approved">Foto goedgekeurd</option>
                      <option value="access_valid">Geldige tourtoegang</option>
                    </select>
                  </label>
                  {condition.type !== "access_valid" && (
                    <label>
                      <span>Eerdere module</span>
                      <select value={condition.module_id || ""} onChange={(event) => {
                        const next = [...module.conditions];
                        next[conditionIndex] = { ...condition, module_id: event.target.value };
                        onPatch({ conditions: next });
                      }}>
                        {previousModules.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.title || TYPES[candidate.type]?.label}</option>)}
                      </select>
                    </label>
                  )}
                  {condition.type === "quiz_score_at_least" && (
                    <label><span>Score (%)</span><input type="number" min="0" max="100" value={condition.value || 0} onChange={(event) => {
                      const next = [...module.conditions];
                      next[conditionIndex] = { ...condition, value: String(event.target.value) };
                      onPatch({ conditions: next });
                    }} /></label>
                  )}
                  <button type="button" className="button" onClick={() => onPatch({ conditions: module.conditions.filter((_, itemIndex) => itemIndex !== conditionIndex) })}>Verwijder regel</button>
                </div>
              ))}
              {previousModules.length === 0 && <p>De eerste module is altijd direct zichtbaar.</p>}
            </div>
          </details>
        </div>
      )}
    </article>
  );
}

function Builder({ root }) {
  const chapterId = Number(root.dataset.chapterId);
  const config = window.sbdpExperienceBuilder || {};
  const [document, setDocument] = useState(null);
  const [query, setQuery] = useState("");
  const [dirty, setDirty] = useState(false);
  const [status, setStatus] = useState("loading");
  const [errors, setErrors] = useState([]);
  const [draggedIndex, setDraggedIndex] = useState(null);
  const [preview, setPreview] = useState(false);
  const [migration, setMigration] = useState(null);
  const savedRef = useRef("");
  const endpoint = `${String(config.endpoint || "").replace(/\/$/, "")}/${chapterId}/modules`;

  useEffect(() => {
    fetch(endpoint, { credentials: "same-origin", headers: { "X-WP-Nonce": config.nonce || "" } })
      .then(async (response) => {
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || "Laden mislukt.");
        return payload;
      })
      .then((payload) => {
        const next = payload.has_modular_document
          ? payload.document
          : { schema_version: 1, document_id: "", revision: 1, modules: [] };
        setDocument(next);
        setMigration(payload.migration || null);
        savedRef.current = JSON.stringify(next);
        setStatus("ready");
      })
      .catch((error) => {
        setErrors([error.message]);
        setStatus("error");
      });
  }, [endpoint, config.nonce]);

  useEffect(() => {
    const warn = (event) => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  const mutate = (updater) => {
    setDocument((current) => {
      const next = updater(current);
      setDirty(JSON.stringify(next) !== savedRef.current);
      return next;
    });
  };
  const modules = document?.modules || [];
  const library = useMemo(
    () => Object.entries(TYPES).filter(([, item]) => item.label.toLowerCase().includes(query.toLowerCase())),
    [query]
  );

  const patch = (index, changes) =>
    mutate((current) => ({
      ...current,
      modules: current.modules.map((item, itemIndex) => itemIndex === index ? { ...item, ...changes } : item),
    }));

  const move = (index, delta) =>
    mutate((current) => {
      const next = [...current.modules];
      const [item] = next.splice(index, 1);
      next.splice(index + delta, 0, item);
      return { ...current, modules: next };
    });

  const moveTo = (from, to) => {
    if (from === null || from === to) return;
    mutate((current) => {
      const next = [...current.modules];
      const [item] = next.splice(from, 1);
      next.splice(to, 0, item);
      return { ...current, modules: next };
    });
  };

  const save = async () => {
    setStatus("saving");
    setErrors([]);
    try {
      const response = await fetch(endpoint, {
        method: "PUT",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": config.nonce || "" },
        body: JSON.stringify({ expected_revision: Number(document.revision || 0), document }),
      });
      const payload = await response.json();
      if (!response.ok) {
        const fieldErrors = payload.data?.errors?.map((item) => `${item.path}: ${item.message}`) || [];
        throw Object.assign(new Error(payload.message || "Opslaan mislukt."), { fieldErrors });
      }
      setDocument(payload.document);
      savedRef.current = JSON.stringify(payload.document);
      setDirty(false);
      setStatus("saved");
    } catch (error) {
      setErrors(error.fieldErrors?.length ? error.fieldErrors : [error.message]);
      setStatus("error");
    }
  };

  const runMigration = async (action) => {
    if (!migration?.confirmation) return;
    const label = action === "migrate"
      ? "Legacy-inhoud omzetten naar modules? De originele velden blijven behouden."
      : "Terugzetten naar de legacy-renderer? Dit kan alleen zolang het gemigreerde document niet is bewerkt.";
    if (!window.confirm(label)) return;
    setStatus("saving");
    setErrors([]);
    try {
      const response = await fetch(`${endpoint.replace(/\/modules$/, "")}/migration`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-WP-Nonce": config.nonce || "" },
        body: JSON.stringify({ action, confirmation: migration.confirmation }),
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || "Migratie mislukt.");
      const refreshed = await fetch(endpoint, { credentials: "same-origin", headers: { "X-WP-Nonce": config.nonce || "" } });
      const payload = await refreshed.json();
      if (!refreshed.ok) throw new Error(payload.message || "Verversen mislukt.");
      const next = payload.has_modular_document
        ? payload.document
        : { schema_version: 1, document_id: "", revision: 1, modules: [] };
      setDocument(next);
      setMigration(payload.migration || null);
      savedRef.current = JSON.stringify(next);
      setDirty(false);
      setStatus("saved");
    } catch (error) {
      setErrors([error.message]);
      setStatus("error");
    }
  };

  if (status === "loading" || !document) return <p role="status">Modules worden geladen…</p>;

  const applyTemplate = (template) => {
    if (modules.length > 0 && !window.confirm("De huidige modules vervangen door deze template?")) return;
    const nextModules = template.types.map((type, index) => {
      const item = emptyModule(type);
      if (index > 0 && ["quiz", "reward"].includes(type)) {
        item.conditions = [{ type: "module_completed", module_id: "", operator: "is", value: "1" }];
      }
      return item;
    });
    nextModules.forEach((item, index) => {
      if (item.conditions?.length) item.conditions[0].module_id = nextModules[index - 1].id;
    });
    mutate((current) => ({ ...current, modules: nextModules }));
  };

  return (
    <section className="sbdp-eb" aria-label="Experience Builder">
      <div className="sbdp-eb__toolbar">
        <div>
          <h2>Hoofdstukmodules</h2>
          <p>Combineer inhoud en media in de gewenste volgorde.</p>
        </div>
        <div className="sbdp-eb__toolbar-actions">
          <button type="button" className="button" aria-pressed={preview} onClick={() => setPreview((value) => !value)}>{preview ? "Editor bekijken" : "Snelle preview"}</button>
          <button type="button" className="button button-primary" onClick={save} disabled={!dirty || status === "saving"}>
            {status === "saving" ? "Opslaan…" : "Modules opslaan"}
          </button>
        </div>
      </div>
      <div className="sbdp-eb__status" role="status" aria-live="polite">
        {dirty ? "Niet-opgeslagen wijzigingen" : status === "saved" ? "Modules opgeslagen" : "Alles opgeslagen"}
      </div>
      {errors.length > 0 && (
        <div className="notice notice-error inline" role="alert">
          <p>{errors.join(" ")}</p>
        </div>
      )}
      {migration?.can_migrate && (
        <section className="sbdp-eb__migration" aria-label="Legacy migratie">
          <div>
            <h3>Legacy-hoofdstuk gevonden</h3>
            <p>Dry-run: {migration.module_summary?.length || 0} modules worden aangemaakt. Originele content en metadata blijven ongewijzigd.</p>
            <ul>{(migration.module_summary || []).map((item, index) => <li key={`${item.type}-${index}`}>{TYPES[item.type]?.label || item.type}</li>)}</ul>
          </div>
          <button type="button" className="button" onClick={() => runMigration("migrate")} disabled={status === "saving"}>Gecontroleerd migreren</button>
        </section>
      )}
      {migration?.can_rollback && (
        <section className="sbdp-eb__migration sbdp-eb__migration--rollback" aria-label="Migratie rollback">
          <div><h3>Rollback beschikbaar</h3><p>Het gemigreerde document is nog ongewijzigd en kan veilig terug naar de legacy-renderer.</p></div>
          <button type="button" className="button" onClick={() => runMigration("rollback")} disabled={status === "saving"}>Rollback naar legacy</button>
        </section>
      )}
      <section className="sbdp-eb__templates" aria-label="Hoofdstuktemplates">
        <h3>Start met een template</h3>
        <div className="sbdp-eb__template-grid">
          {TEMPLATES.map((template) => (
            <button type="button" key={template.id} onClick={() => applyTemplate(template)}>
              <strong>{template.label}</strong><span>{template.description}</span>
            </button>
          ))}
        </div>
      </section>
      {preview ? (
        <section className="sbdp-eb__preview" aria-label="Snelle hoofdstukpreview">
          <div className="sbdp-eb__preview-head"><h3>Hoofdstukpreview</h3><span>Interactieve onderdelen worden hier niet gestart.</span></div>
          {modules.filter((module) => module.enabled).map((module) => (
            <article key={module.id}>
              <small>{TYPES[module.type]?.label || module.type}</small>
              <h4>{module.title || TYPES[module.type]?.label}</h4>
              {module.type === "text" && <div>{String(module.content?.html || "").replace(/<[^>]+>/g, " ").trim() || "Nog geen tekst"}</div>}
              {["image", "audio", "video"].includes(module.type) && <p>{module.content?.url ? "Media geselecteerd" : "Nog geen media geselecteerd"}</p>}
              {module.type === "sketchfab" && <p>{module.content?.model_uid ? "3D-model geconfigureerd" : "Nog geen 3D-model"}</p>}
              {module.type === "ai_photo_challenge" && <p>Discovery Camera-opdracht</p>}
              {module.type === "quiz" && <p>Server-gevalideerde hoofdstukquiz</p>}
              {module.type === "reward" && <p>{module.content?.message || "Beloning"}</p>}
              {(module.conditions || []).length > 0 && <span className="sbdp-eb__preview-lock">Wordt conditioneel ontgrendeld</span>}
            </article>
          ))}
        </section>
      ) : (
      <>
      <div className="sbdp-eb__library">
        <label>
          <span>Zoek module</span>
          <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} />
        </label>
        <div className="sbdp-eb__library-items">
          {library.map(([type, item]) => (
            <button
              key={type}
              type="button"
              onClick={() => mutate((current) => ({ ...current, modules: [...current.modules, emptyModule(type)] }))}
            >
              <span>{item.label}</span><small>{item.category}</small>
            </button>
          ))}
        </div>
      </div>
      <div className="sbdp-eb__modules">
        {modules.length === 0 ? (
          <div className="sbdp-eb__empty">
            <h3>Nog geen modules</h3>
            <p>Voeg hierboven tekst of media toe.</p>
          </div>
        ) : modules.map((module, index) => (
          <ModuleCard
            key={module.id}
            module={module}
            index={index}
            total={modules.length}
            previousModules={modules.slice(0, index)}
            onPatch={(changes) => patch(index, changes)}
            onMove={(delta) => move(index, delta)}
            onDragStart={() => setDraggedIndex(index)}
            onDrop={() => {
              moveTo(draggedIndex, index);
              setDraggedIndex(null);
            }}
            onDuplicate={() => mutate((current) => {
              const next = [...current.modules];
              next.splice(index + 1, 0, { ...module, id: uuid(), title: `${module.title || TYPES[module.type]?.label} kopie` });
              return { ...current, modules: next };
            })}
            onRemove={() => {
              if (window.confirm(`Module “${module.title || TYPES[module.type]?.label}” verwijderen?`)) {
                mutate((current) => ({ ...current, modules: current.modules.filter((_, itemIndex) => itemIndex !== index) }));
              }
            }}
          />
        ))}
      </div>
      </>
      )}
    </section>
  );
}

const root = document.getElementById("sbdp-experience-builder-root");
if (root && !root.dataset.mounted) {
  root.dataset.mounted = "true";
  createRoot(root).render(<Builder root={root} />);
}

export { Builder, ModuleCard, emptyModule };
