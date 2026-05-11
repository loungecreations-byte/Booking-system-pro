import React from 'react';
import PropTypes from 'prop-types';

export default function ActivitySwitcher({ 
  activityId,
  slotKey, 
  alternatives, 
  currentIndex, 
  onSwitch 
}) {
  // Safety checks
  if (!alternatives || alternatives.length <= 1) {
    return null;
  }
  
  if (typeof onSwitch !== 'function') {
    console.warn('[ActivitySwitcher] onSwitch is not a function');
    return null;
  }
  
  const currentIdx = currentIndex || 0;
  const nextAlt = alternatives[(currentIdx + 1) % alternatives.length];
  
  if (!nextAlt) {
    console.warn('[ActivitySwitcher] No next alternative found');
    return null;
  }
  
  return (
    <div className="sbdp-activity-switcher">
      <button 
        type="button"
        className="sbdp-switch-btn"
        onClick={() => onSwitch(slotKey, activityId, nextAlt)}
        title={`Wissel naar: ${nextAlt.name}`}
        aria-label={`Wissel activiteit naar ${nextAlt.name}`}
      >
        <svg className="sbdp-switch-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/>
        </svg>
        <span className="sbdp-switch-hint">
          {alternatives.length - 1} {alternatives.length === 2 ? 'alternatief' : 'alternatieven'}
        </span>
      </button>
    </div>
  );
}

ActivitySwitcher.propTypes = {
  activityId: PropTypes.string.isRequired,
  slotKey: PropTypes.string.isRequired,
  alternatives: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.number.isRequired,
      name: PropTypes.string.isRequired,
      score: PropTypes.number.isRequired,
      duration: PropTypes.number,
      category: PropTypes.string,
      resource_id: PropTypes.number,
    })
  ),
  currentIndex: PropTypes.number,
  onSwitch: PropTypes.func.isRequired,
};
