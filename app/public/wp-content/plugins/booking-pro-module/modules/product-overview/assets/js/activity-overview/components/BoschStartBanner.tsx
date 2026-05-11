import React from "react";

export default function BoschStartBanner() {
  return (
    <section className="ao-banner ui-panel ui-panel--soft">
      <div className="ao-banner__content">
        <p className="ao-banner__eyebrow">Ontdek plekken in Den Bosch</p>
        <h2 className="ao-banner__title">Van lunch tot bijzondere stops die je dag echt sterker maken.</h2>
        <p className="ao-banner__copy">
          Verken, filter en kies plekken die passen bij je route, zodat je ze direct kunt toevoegen aan je dagplan.
        </p>
      </div>
      <div className="ao-banner__actions">
        <a className="ui-btn ui-btn--primary ui-btn--planner ao-button--wide" href="/plan-je-dag">
          Plan je dag
        </a>
      </div>
    </section>
  );
}
