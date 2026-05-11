import React, { useEffect, useMemo, useRef } from "react";
import type { Activity } from "../types";

interface ActivityWorkspaceRailProps {
  activities: Activity[];
  selectedActivity: Activity | null;
  onSelectActivity: (activity: Activity) => void;
  variant?: "default" | "archive";
}

declare global {
  interface Window {
    L?: any;
  }
}

const DEFAULT_CENTER: [number, number] = [51.6978, 5.3037];

export default function ActivityWorkspaceRail({
  activities,
  selectedActivity,
  onSelectActivity,
  variant = "default",
}: ActivityWorkspaceRailProps) {
  const isArchiveVariant = variant === "archive";
  const mapRef = useRef<HTMLDivElement | null>(null);
  const mapInstanceRef = useRef<any>(null);
  const markerLayerRef = useRef<any>(null);
  const markerIndexRef = useRef<Map<number, any>>(new Map());

  const mapActivities = useMemo(
    () => activities.filter((activity) => activity.coordinates.lat !== null && activity.coordinates.lng !== null),
    [activities]
  );

  const summary = selectedActivity
    ? trimCopy(stripHtml(selectedActivity.excerpt || "Een activiteit die logisch past binnen je dag."), 132)
    : "Selecteer een activiteit om meteen de context, route en vervolgstap te zien.";

  const helperLines = selectedActivity
    ? [
        selectedActivity.durationLabel ? `Duur: ${selectedActivity.durationLabel}` : "Korte activiteit",
        selectedActivity.locationLabel ? `Buurt: ${selectedActivity.locationLabel}` : "Logische stop op je route",
        selectedActivity.statusLabel,
      ]
    : ["Kies een activiteit", "Bekijk de context", "Voeg toe als het echt past"];

  useEffect(() => {
    const L = window.L;
    if (!L || !mapRef.current) {
      return;
    }

    if (!mapInstanceRef.current) {
      const mapShell = mapRef.current.parentElement;
      mapInstanceRef.current = L.map(mapRef.current, {
        zoomControl: false,
        scrollWheelZoom: false,
      }).setView(DEFAULT_CENTER, 13);

      const tileLayer = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; OpenStreetMap contributors",
        maxZoom: 18,
      });

      tileLayer.on("load", () => {
        mapShell?.classList.add("is-ready");
        mapShell?.classList.remove("is-tileless");
      });

      tileLayer.on("tileerror", () => {
        mapShell?.classList.add("is-tileless");
        mapShell?.classList.remove("is-ready");
      });

      tileLayer.addTo(mapInstanceRef.current);

      markerLayerRef.current = L.layerGroup().addTo(mapInstanceRef.current);
    }

    const map = mapInstanceRef.current;
    const markerLayer = markerLayerRef.current;
    if (!markerLayer) {
      return;
    }

    markerLayer.clearLayers();
    markerIndexRef.current.clear();

    const bounds: any[] = [];
    mapActivities.forEach((activity) => {
      const coords = [activity.coordinates.lat, activity.coordinates.lng] as [number, number];
      const marker = L.marker(coords, {
        icon: L.divIcon({
          className: `ao-map-marker ${selectedActivity?.id === activity.id ? "is-selected" : ""}`,
          html: `<span class="ao-map-marker__inner"></span>`,
          iconSize: [18, 18],
          iconAnchor: [9, 9],
        }),
      }).addTo(markerLayer);

      marker.bindPopup(buildPopup(activity));
      marker.on("click", () => onSelectActivity(activity));
      markerIndexRef.current.set(activity.id, marker);
      bounds.push(coords);
    });

    if (bounds.length > 1) {
      map.fitBounds(bounds, { padding: [32, 32] });
    } else if (bounds.length === 1) {
      map.setView(bounds[0], 14);
    } else {
      map.setView(DEFAULT_CENTER, 13);
    }

    setTimeout(() => {
      map.invalidateSize();
    }, 80);
  }, [mapActivities, onSelectActivity, selectedActivity]);

  useEffect(() => {
    if (!selectedActivity || !mapInstanceRef.current) {
      return;
    }

    const marker = markerIndexRef.current.get(selectedActivity.id);
    const coords = selectedActivity.coordinates;
    if (coords.lat === null || coords.lng === null) {
      return;
    }

    mapInstanceRef.current.setView([coords.lat, coords.lng], Math.max(mapInstanceRef.current.getZoom(), 14));
    marker?.openPopup?.();
  }, [selectedActivity]);

  return (
    <aside className="ao-rail ao-rail--minimal" aria-label="Contextuele selectie">
      <section className="ao-rail-card ao-rail-card--map">
        <div className="ao-rail-card__header">
          <div>
            <p className="ao-rail-card__eyebrow">Kaart</p>
            <h3 className="ao-rail-card__title">Kaart & selectie</h3>
          </div>
          <span className="ao-rail-card__badge">Routecontext</span>
        </div>

        <div className="ao-map ao-map--compact" aria-label="Spots op de kaart">
          <div ref={mapRef} className="ao-map__canvas" />
          <div className="ao-map__fallback" aria-hidden="true">
            <span className="ao-map__grid" />
            <span className="ao-map__blob ao-map__blob--one" />
            <span className="ao-map__blob ao-map__blob--two" />
            <span className="ao-map__pin ao-map__pin--one" />
            <span className="ao-map__pin ao-map__pin--two" />
            <span className="ao-map__pin ao-map__pin--three" />
          </div>
        </div>

        <div className="ao-rail-card__meta">
          <span>{mapActivities.length ? `${mapActivities.length} activiteiten op de kaart` : "Kaartcontext volgt na selectie"}</span>
          <span>{selectedActivity ? "Open route extern" : "Selecteer een activiteit"}</span>
        </div>
      </section>

      <section className="ao-rail-card ao-rail-card--selection">
        <div className="ao-rail-card__header">
          <div>
            <p className="ao-rail-card__eyebrow">Geselecteerde activiteit</p>
            <h3 className="ao-rail-card__title">{isArchiveVariant ? "Past in jouw dag" : "Overzicht & vervolgstap"}</h3>
          </div>
          {selectedActivity ? <span className="ao-rail-card__badge">{selectedActivity.priceLevelLabel}</span> : null}
        </div>

        {selectedActivity ? (
          <div className="ao-context">
            <p className="ao-context__meta">
              {selectedActivity.statusLabel}
              <span aria-hidden="true">•</span>
              {selectedActivity.primaryTypeLabel || "Activiteit"}
              <span aria-hidden="true">•</span>
              {selectedActivity.locationLabel || "Den Bosch"}
            </p>
            <p className="ao-context__copy">{summary}</p>

            <div className="ao-context__actions">
              <a className="ui-btn ui-btn--primary" href={selectedActivity.permalink}>
                {selectedActivity.isRequestOnly ? "Bekijk aanvraag" : "Bekijk activiteit"}
              </a>
              <a className="ui-btn ui-btn--secondary" href={selectedActivity.plannerHref}>
                {selectedActivity.isRequestOnly ? "Plan aanvraag" : "Plan direct"}
              </a>
              <a
                className="ui-btn ui-btn--ghost"
                href={
                  selectedActivity.coordinates.lat !== null && selectedActivity.coordinates.lng !== null
                    ? `https://www.google.com/maps/dir/?api=1&destination=${selectedActivity.coordinates.lat},${selectedActivity.coordinates.lng}`
                    : `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(selectedActivity.addressLabel)}`
                }
                target="_blank"
                rel="noreferrer"
              >
                Route
              </a>
            </div>
          </div>
        ) : (
          <p className="ao-rail-card__body">{summary}</p>
        )}
      </section>

      <section className="ao-rail-card ao-rail-card--helper">
        <p className="ao-rail-card__eyebrow">Handige context</p>
        <ul className="ao-context-list" role="list">
          {helperLines.map((line) => (
            <li key={line} className="ao-context-list__item">
              {line}
            </li>
          ))}
        </ul>
      </section>
    </aside>
  );
}

function trimCopy(value: string, maxLength: number): string {
  if (value.length <= maxLength) {
    return value;
  }

  return `${value.slice(0, Math.max(0, maxLength - 1)).trimEnd()}…`;
}

function stripHtml(value: string): string {
  const wrapper = document.createElement("div");
  wrapper.innerHTML = String(value || "");
  return String(wrapper.textContent || wrapper.innerText || "").replace(/\s+/g, " ").trim();
}

function buildPopup(activity: Activity): string {
  return `
      <div class="sbdp-po-popup ao-map-popup">
        <h4>${escapeHtml(activity.title)}</h4>
        <p>${escapeHtml(activity.primaryTypeLabel || "Activiteit")}</p>
        <p>${escapeHtml(activity.locationLabel || "Den Bosch")}</p>
      </div>
    `;
}

function escapeHtml(value: string): string {
  const div = document.createElement("div");
  div.textContent = value || "";
  return div.innerHTML;
}
