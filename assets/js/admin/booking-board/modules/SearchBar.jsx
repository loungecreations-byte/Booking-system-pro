import React from "react";
import PropTypes from "prop-types";

function SearchBar({ value, onChange }) {
  const handleChange = (event) => onChange(event.target.value);

  return (
    <div className="sbdp-search-bar">
      <input
        type="search"
        placeholder="Search bookings..."
        value={value}
        onChange={handleChange}
      />
    </div>
  );
}

SearchBar.propTypes = {
  value: PropTypes.string,
  onChange: PropTypes.func.isRequired,
};

SearchBar.defaultProps = {
  value: "",
};

export default SearchBar;
