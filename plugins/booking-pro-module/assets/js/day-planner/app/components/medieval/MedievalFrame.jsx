import React from "react";
import PropTypes from "prop-types";

function CornerOrnament({ className, flipX, flipY }) {
  const scaleX = flipX ? -1 : 1;
  const scaleY = flipY ? -1 : 1;
  return (
    <svg
      className={className}
      viewBox="0 0 28 28"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
      style={{ transform: `scale(${scaleX}, ${scaleY})` }}
    >
      <path d="M3 3H14C18 3 21 6 21 10V14" stroke="currentColor" strokeWidth="2" fill="none" />
      <path d="M4 16C8 16 10 18 10 24" stroke="currentColor" strokeWidth="1.6" fill="none" />
      <circle cx="4" cy="4" r="2" fill="currentColor" />
    </svg>
  );
}

CornerOrnament.propTypes = {
  className: PropTypes.string.isRequired,
  flipX: PropTypes.bool,
  flipY: PropTypes.bool,
};

CornerOrnament.defaultProps = {
  flipX: false,
  flipY: false,
};

export default function MedievalFrame({ src, alt, className, children }) {
  return (
    <div className={`sbdp-medieval-frame ${className}`.trim()}>
      <div className="sbdp-medieval-frame__inner">
        <img src={src} alt={alt} loading="lazy" referrerPolicy="no-referrer" />
        <CornerOrnament className="sbdp-medieval-frame__corner sbdp-medieval-frame__corner--tl" />
        <CornerOrnament className="sbdp-medieval-frame__corner sbdp-medieval-frame__corner--tr" flipX />
        <CornerOrnament className="sbdp-medieval-frame__corner sbdp-medieval-frame__corner--bl" flipY />
        <CornerOrnament className="sbdp-medieval-frame__corner sbdp-medieval-frame__corner--br" flipX flipY />
      </div>
      {children ? <div className="sbdp-medieval-frame__overlay">{children}</div> : null}
    </div>
  );
}

MedievalFrame.propTypes = {
  src: PropTypes.string.isRequired,
  alt: PropTypes.string,
  className: PropTypes.string,
  children: PropTypes.node,
};

MedievalFrame.defaultProps = {
  alt: "",
  className: "",
  children: null,
};
