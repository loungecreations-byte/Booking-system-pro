import React from 'react';
import { createRoot } from 'react-dom/client';
import ProductBookingPage from './ProductBookingPage.jsx';

// Mount React component
const rootElement = document.getElementById('sbdp-product-booking-root');

if (rootElement) {
  const root = createRoot(rootElement);
  
  // Get data from DOM attributes
  const productId = rootElement.dataset.productId;
  const restBase = rootElement.dataset.restBase;
  const nonce = rootElement.dataset.nonce;
  
  root.render(
    <React.StrictMode>
      <ProductBookingPage 
        productId={productId}
        restBase={restBase}
        nonce={nonce}
      />
    </React.StrictMode>
  );
}
