import React from "react";

export default function BoschStartBanner() {
  return (
    <section className="ao-banner ui-panel ui-panel--soft">
      <div className="ao-banner__content">
        <p className="ao-banner__eyebrow">Ontdek plekken in Den Bosch</p>
        <h2 className="ao-banner__title">Kies plekken die je dag sterker maken.</h2>
        <p className="ao-banner__copy">
          Verken rustig, filter snel en open daarna meteen de planner voor een logische dagindeling.
        </p>
      </div>
      <div className="ao-banner__actions">
        <a className="ui-btn ui-btn--primary ui-btn--planner ao-button--wide" href="/plan-je-dag">
          Open planner
        </a>
      </div>
    </section>
  );
}
