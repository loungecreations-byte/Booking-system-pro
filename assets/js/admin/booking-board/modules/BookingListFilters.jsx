import React from "react";
import PropTypes from "prop-types";

import SearchBar from "./SearchBar";

const STATUS_OPTIONS = ["created", "requested", "captured", "pending", "paid", "completed", "cancelled"];

function BookingListFilters({ filters, onChange }) {
  const toggleStatus = (status) => {
    const next = filters.status.includes(status)
      ? filters.status.filter((value) => value !== status)
      : [...filters.status, status];
    onChange({ ...filters, status: next });
  };

  const handleSearch = (value) => {
    onChange({ ...filters, search: value });
  };

  return (
    <div className="sbdp-booking-filters">
      <SearchBar value={filters.search} onChange={handleSearch} />
      <div className="sbdp-booking-filters__statuses">
        {STATUS_OPTIONS.map((status) => (
          <label key={status} className="sbdp-booking-filters__status">
            <input
              type="checkbox"
              checked={filters.status.includes(status)}
              onChange={() => toggleStatus(status)}
            />
            <span>{status.charAt(0).toUpperCase() + status.slice(1)}</span>
          </label>
        ))}
      </div>
    </div>
  );
}

BookingListFilters.propTypes = {
  filters: PropTypes.shape({
    search: PropTypes.string.isRequired,
    status: PropTypes.arrayOf(PropTypes.string).isRequired,
  }).isRequired,
  onChange: PropTypes.func.isRequired,
};

export default BookingListFilters;
