import PropTypes from "prop-types";

function BookingDetailsPanel({
  booking,
  onClose,
  onInvoice,
  invoicing,
  onDownloadInvoice,
  downloading,
}) {
  if (!booking) {
    return (
      <div className="sbdp-details-panel sbdp-details-panel--empty">
        <p>Select a booking to see details.</p>
      </div>
    );
  }

  const customerDetails = booking.customer_details || {};
  const billing = customerDetails.billing?.formatted || "";
  const shipping = customerDetails.shipping?.formatted || "";
  const phone = customerDetails.phone || booking.customer_phone || "";
  const company = customerDetails.company || "";
  const order = booking.order || null;
  const paymentRequest = booking.payment_request || null;

  return (
    <div className="sbdp-details-panel">
      <header className="sbdp-details-panel__header">
        <h3>Booking #{booking.booking_id}</h3>
        <div className="sbdp-details-panel__actions">
          {onDownloadInvoice && (
            <button
              type="button"
              onClick={() => onDownloadInvoice(booking.booking_id)}
              disabled={downloading}
            >
              {downloading ? "Generating PDF..." : "Download invoice"}
            </button>
          )}
          {onInvoice && (
            <button
              type="button"
              onClick={() => onInvoice(booking.booking_id)}
              disabled={invoicing}
            >
              {invoicing ? "Sending invoice." : "Send invoice"}
            </button>
          )}
          <button type="button" onClick={onClose}>
            Close
          </button>
        </div>
      </header>
      <dl className="sbdp-details-panel__list">
        <div>
          <dt>Product</dt>
          <dd>{booking.product}</dd>
        </div>
        <div>
          <dt>Customer</dt>
          <dd>
            {booking.customer} (<a href={`mailto:${booking.customer_email}`}>{booking.customer_email}</a>)
          </dd>
        </div>
        <div>
          <dt>Contact</dt>
          <dd>
            {phone || "-"}
            {company ? ` | ${company}` : ""}
          </dd>
        </div>
        <div>
          <dt>Schedule</dt>
          <dd>
            {booking.from} - {booking.to}
          </dd>
        </div>
        <div>
          <dt>People</dt>
          <dd>{booking.people}</dd>
        </div>
        <div>
          <dt>Status</dt>
          <dd>{booking.status}</dd>
        </div>
        <div>
          <dt>Billing</dt>
          <dd>{billing || "-"}</dd>
        </div>
        <div>
          <dt>Shipping</dt>
          <dd>{shipping || "-"}</dd>
        </div>
        <div>
          <dt>Order</dt>
          <dd>
            {order && order.id ? (
              <>
                #{order.number || order.id}
                {paymentRequest?.status ? ` | ${paymentRequest.status}` : ""}
                {paymentRequest?.url ? (
                  <>
                    {" | "}
                    <a href={paymentRequest.url} target="_blank" rel="noreferrer">
                      Payment link
                    </a>
                  </>
                ) : null}
              </>
            ) : (
              "-"
            )}
          </dd>
        </div>
        <div>
          <dt>Notes</dt>
          <dd>{booking.notes || "-"}</dd>
        </div>
      </dl>
    </div>
  );
}

BookingDetailsPanel.propTypes = {
  booking: PropTypes.object,
  onClose: PropTypes.func.isRequired,
  onInvoice: PropTypes.func,
  invoicing: PropTypes.bool,
  onDownloadInvoice: PropTypes.func,
  downloading: PropTypes.bool,
};

BookingDetailsPanel.defaultProps = {
  booking: null,
  onInvoice: null,
  invoicing: false,
  onDownloadInvoice: null,
  downloading: false,
};

export default BookingDetailsPanel;

