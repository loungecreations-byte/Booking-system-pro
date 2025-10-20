import React from "react";
import PropTypes from "prop-types";

import InlineStatusEdit from "./InlineStatusEdit";

function resolveValue(item, columnId) {
  if (columnId === "price" && item.price) {
    return `${item.price.amount.toFixed(2)} ${item.price.currency}`;
  }

  return item[columnId] ?? "";
}

function BookingListRow({ item, columns, colorMap, onRowClick, onStatusChange }) {
  const handleRowClick = () => {
    if (onRowClick) {
      onRowClick(item);
    }
  };

  const handleStatusChange = (status) => {
    if (onStatusChange) {
      onStatusChange(item, status);
    }
  };

  return (
    <tr className="sbdp-booking-list__row" onClick={handleRowClick}>
      {columns.map((column) => {
        if (column.id === "status") {
          const color = colorMap[item.status] || "";
          return (
            <td key={column.id} onClick={(event) => event.stopPropagation()}>
              <InlineStatusEdit
                value={item.status}
                color={color}
                onChange={handleStatusChange}
              />
            </td>
          );
        }

        return <td key={column.id}>{resolveValue(item, column.id)}</td>;
      })}
    </tr>
  );
}

BookingListRow.propTypes = {
  item: PropTypes.object.isRequired,
  columns: PropTypes.arrayOf(
    PropTypes.shape({
      id: PropTypes.string.isRequired,
      label: PropTypes.string.isRequired,
    })
  ).isRequired,
  colorMap: PropTypes.object,
  onRowClick: PropTypes.func,
  onStatusChange: PropTypes.func,
};

BookingListRow.defaultProps = {
  colorMap: {},
  onRowClick: null,
  onStatusChange: null,
};

export default BookingListRow;
