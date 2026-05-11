import React from "react";
import PropTypes from "prop-types";
import { __ } from "@wordpress/i18n";

import BookingListFilters from "./BookingListFilters";
import BookingListRow from "./BookingListRow";
import FilterPresets from "./FilterPresets";

function BookingList({
  columns,
  items,
  filters,
  onFiltersChange,
  onRowClick,
  onStatusChange,
  colorMap,
  loading,
  personalPresets,
  sharedPresets,
  canManageShared,
  defaultSharedPresetId,
  onPresetApply,
  onPresetSave,
  onPresetDelete,
  onPresetSetDefault,
  presetsLoading,
  presetSaving,
  presetDeletingId,
  statusOptions,
}) {
  return (
    <div className="sbdp-booking-list">
      {onPresetApply && onPresetSave && onPresetDelete ? (
        <FilterPresets
          personalPresets={personalPresets}
          sharedPresets={sharedPresets}
          canManageShared={Boolean(canManageShared)}
          defaultSharedPresetId={defaultSharedPresetId}
          onApply={onPresetApply}
          onSave={onPresetSave}
          onDelete={onPresetDelete}
          onSetDefault={onPresetSetDefault}
          loading={Boolean(presetsLoading)}
          saving={Boolean(presetSaving)}
          deletingId={presetDeletingId}
        />
      ) : null}
      <BookingListFilters filters={filters} onChange={onFiltersChange} statusOptions={statusOptions} />
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
                  {__("Loading…", "sbdp")}
                </td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="sbdp-table__empty">
                  {__("No bookings found for the selected filters.", "sbdp")}
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
                  statusOptions={statusOptions}
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
  personalPresets: PropTypes.arrayOf(PropTypes.object),
  sharedPresets: PropTypes.arrayOf(PropTypes.object),
  canManageShared: PropTypes.bool,
  defaultSharedPresetId: PropTypes.string,
  onPresetApply: PropTypes.func,
  onPresetSave: PropTypes.func,
  onPresetDelete: PropTypes.func,
  onPresetSetDefault: PropTypes.func,
  presetsLoading: PropTypes.bool,
  presetSaving: PropTypes.bool,
  presetDeletingId: PropTypes.string,
  statusOptions: PropTypes.arrayOf(
    PropTypes.shape({
      value: PropTypes.string.isRequired,
      label: PropTypes.string.isRequired,
    })
  ),
};

BookingList.defaultProps = {
  items: [],
  onRowClick: null,
  onStatusChange: null,
  colorMap: {},
  loading: false,
  personalPresets: [],
  sharedPresets: [],
  canManageShared: false,
  defaultSharedPresetId: null,
  onPresetApply: null,
  onPresetSave: null,
  onPresetDelete: null,
  onPresetSetDefault: null,
  presetsLoading: false,
  presetSaving: false,
  presetDeletingId: "",
  statusOptions: [],
};

export default BookingList;

