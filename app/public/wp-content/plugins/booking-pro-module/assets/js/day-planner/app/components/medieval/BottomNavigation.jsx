import React from "react";
import PropTypes from "prop-types";

const ITEMS = [
  { id: "home", label: "Home", icon: "⌂" },
  { id: "map", label: "Schatkaart", icon: "🗺" },
  { id: "favorites", label: "Favorieten", icon: "♥" },
  { id: "planner", label: "Planner", icon: "✦" },
  { id: "account", label: "Account", icon: "⚜" },
];

export default function BottomNavigation({ activeScreen, onChange }) {
  return (
    <nav className="sbdp-medieval-nav" aria-label="Hoofdnavigatie">
      {ITEMS.map((item) => {
        const isActive = activeScreen === item.id;
        return (
          <button
            key={item.id}
            type="button"
            className={`sbdp-medieval-nav__item ${isActive ? "is-active" : ""}`.trim()}
            onClick={() => onChange(item.id)}
            aria-current={isActive ? "page" : undefined}
          >
            <span className="sbdp-medieval-nav__icon" aria-hidden="true">
              {item.icon}
            </span>
            <span className="sbdp-medieval-nav__label">{item.label}</span>
          </button>
        );
      })}
    </nav>
  );
}

BottomNavigation.propTypes = {
  activeScreen: PropTypes.string.isRequired,
  onChange: PropTypes.func.isRequired,
};
