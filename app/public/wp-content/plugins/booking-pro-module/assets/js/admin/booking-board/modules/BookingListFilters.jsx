import React from "react";
import PropTypes from "prop-types";
import { __ } from "@wordpress/i18n";

import SearchBar from "./SearchBar";

function BookingListFilters({ filters, onChange, statusOptions }) {
  const toggleStatus = (status) => {
    const next = filters.status.includes(status)
      ? filters.status.filter((value) => value !== status)
      : [...filters.status, status];
    onChange({ ...filters, status: next });
  };

  const handleSearch = (value) => {
    onChange({ ...filters, search: value });
  };

  const options = statusOptions.length > 0 ? statusOptions : [
    { value: "created", label: __("Created", "sbdp") },
    { value: "requested", label: __("Requested", "sbdp") },
    { value: "captured", label: __("Captured", "sbdp") },
    { value: "pending", label: __("Pending", "sbdp") },
    { value: "paid", label: __("Paid", "sbdp") },
    { value: "completed", label: __("Completed", "sbdp") },
    { value: "cancelled", label: __("Cancelled", "sbdp") },
  ];

  return (
    <div className="sbdp-booking-filters">
      <SearchBar value={filters.search} onChange={handleSearch} />
      <div className="sbdp-booking-filters__statuses">
        {options.map((option) => (
          <label key={option.value} className="sbdp-booking-filters__status">
            <input
              type="checkbox"
              checked={filters.status.includes(option.value)}
              onChange={() => toggleStatus(option.value)}
            />
            <span>{option.label}</span>
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
  statusOptions: PropTypes.arrayOf(
    PropTypes.shape({
      value: PropTypes.string.isRequired,
      label: PropTypes.string.isRequired,
    })
  ),
};

BookingListFilters.defaultProps = {
  statusOptions: [],
};

export default BookingListFilters;
