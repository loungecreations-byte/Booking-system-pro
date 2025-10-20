import React from "react";
import PropTypes from "prop-types";

import BookingListFilters from "./BookingListFilters";
import BookingListRow from "./BookingListRow";

function BookingList({
  columns,
  items,
  filters,
  onFiltersChange,
  onRowClick,
  onStatusChange,
  colorMap,
  loading,
}) {
  return (
    <div className="sbdp-booking-list">
      <BookingListFilters filters={filters} onChange={onFiltersChange} />
      <div className="sbdp-table-wrap">
        <table className="sbdp-table">
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.id}>{column.label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={columns.length} className="sbdp-table__loading">
                  Loading...
                </td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="sbdp-table__empty">
                  No bookings found for the selected filters.
                </td>
              </tr>
            ) : (
              items.map((item) => (
                <BookingListRow
                  key={item.booking_id}
                  item={item}
                  columns={columns}
                  colorMap={colorMap}
                  onRowClick={onRowClick}
                  onStatusChange={onStatusChange}
                />
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

BookingList.propTypes = {
  columns: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      label: PropTypes.string.isRequired,
    })
  ).isRequired,
  items: PropTypes.arrayOf(PropTypes.object),
  filters: PropTypes.shape({
    search: PropTypes.string,
    status: PropTypes.arrayOf(PropTypes.string),
  }).isRequired,
  onFiltersChange: PropTypes.func.isRequired,
  onRowClick: PropTypes.func,
  onStatusChange: PropTypes.func,
  colorMap: PropTypes.object,
  loading: PropTypes.bool,
};

BookingList.defaultProps = {
  items: [],
  onRowClick: null,
  onStatusChange: null,
  colorMap: {},
  loading: false,
};

export default BookingList;
