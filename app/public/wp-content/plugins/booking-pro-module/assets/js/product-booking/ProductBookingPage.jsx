import React, { useState, useEffect } from 'react';
import { Calendar, Clock, MapPin, Users, Star, Check, Info } from 'lucide-react';

export default function ProductBookingPage({ productId, restBase, nonce }) {
  const [product, setProduct] = useState(null);
  const [selectedDate, setSelectedDate] = useState('');
  const [selectedTime, setSelectedTime] = useState('');
  const [participants, setParticipants] = useState(2);
  const [timeSlots, setTimeSlots] = useState([]);
  const [loading, setLoading] = useState(true);

  // Fetch product data
  useEffect(() => {
    async function fetchProduct() {
      try {
        const response = await fetch(`${restBase}/products/${productId}`, {
          headers: { 'X-WP-Nonce': nonce }
        });
        const data = await response.json();
        setProduct(data);
        setLoading(false);
      } catch (error) {
        console.error('Failed to fetch product:', error);
        setLoading(false);
      }
    }
    
    if (productId) {
      fetchProduct();
    }
  }, [productId, restBase, nonce]);

  // Calculate price
  const calculatePrice = () => {
    if (!product) return 0;
    const basePrice = product.price_pp || product.pricing?.per_person || 0;
    return (basePrice * participants).toFixed(2);
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-[#f8f9fa] flex items-center justify-center">
        <div className="text-[#202124] text-lg">Loading...</div>
      </div>
    );
  }

  if (!product) {
    return null;
  }

  return (
    <div className="min-h-screen bg-[#f8f9fa] font-['Inter',sans-serif]">
      {/* Hero Gallery */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-2 h-[400px] rounded-3xl overflow-hidden">
          <div className="relative bg-gray-300">
            {product.images?.[0] ? (
              <img 
                src={product.images[0].src} 
                alt={product.name}
                className="w-full h-full object-cover"
              />
            ) : (
              <div className="w-full h-full flex items-center justify-center text-gray-500">
                Geen afbeelding
              </div>
            )}
          </div>
          <div className="grid grid-rows-2 gap-2">
            <div className="relative bg-gray-300">
              {product.images?.[1] ? (
                <img 
                  src={product.images[1].src} 
                  alt={product.name}
                  className="w-full h-full object-cover"
                />
              ) : (
                <div className="w-full h-full flex items-center justify-center text-gray-500 text-sm">
                  Geen afbeelding
                </div>
              )}
            </div>
            <div className="relative bg-gray-300">
              {product.images?.[2] ? (
                <img 
                  src={product.images[2].src} 
                  alt={product.name}
                  className="w-full h-full object-cover"
                />
              ) : (
                <div className="w-full h-full flex items-center justify-center text-gray-500 text-sm">
                  Geen afbeelding
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Content Grid */}
        <div className="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Left Column - Product Info */}
          <div className="lg:col-span-2 space-y-6">
            {/* Product Header */}
            <div className="bg-white rounded-2xl p-8 shadow-sm">
              <h1 className="text-3xl font-bold text-[#202124] tracking-tight mb-4">
                {product.name}
              </h1>
              
              <div className="flex flex-wrap items-center gap-4 text-sm text-[#5f6368]">
                <div className="flex items-center gap-1">
                  <Star className="w-4 h-4 fill-current text-yellow-400" />
                  <span className="font-semibold text-[#202124]">4.9</span>
                  <span>(127 reviews)</span>
                </div>
                <div className="flex items-center gap-1">
                  <MapPin className="w-4 h-4" />
                  <span>Parade, 's-Hertogenbosch</span>
                </div>
                <div className="flex items-center gap-1">
                  <Clock className="w-4 h-4" />
                  <span>{product.duration_minutes || 90} minuten</span>
                </div>
              </div>
            </div>

            {/* Description */}
            <div className="bg-white rounded-2xl p-8 shadow-sm">
              <h2 className="text-xl font-bold text-[#202124] mb-4">Beschrijving</h2>
              <div 
                className="text-[#5f6368] leading-relaxed prose prose-sm max-w-none"
                dangerouslySetInnerHTML={{ __html: product.description || product.short_description }}
              />
            </div>

            {/* Hoogtepunten */}
            <div className="bg-white rounded-2xl p-8 shadow-sm">
              <h2 className="text-xl font-bold text-[#202124] mb-4">Hoogtepunten</h2>
              <ul className="space-y-3">
                {[
                  'Professionele lokale gids',
                  'Exclusieve toegang tot historische plekken',
                  'Inclusief Bossche Bol proeverij',
                  'Kleine groepen (max 12 personen)'
                ].map((item, i) => (
                  <li key={i} className="flex items-start gap-3">
                    <div className="mt-0.5 p-1 bg-[#d2e3fc] rounded-full">
                      <Check className="w-4 h-4 text-[#343a40]" />
                    </div>
                    <span className="text-[#5f6368]">{item}</span>
                  </li>
                ))}
              </ul>
            </div>

            {/* Wat is inbegrepen */}
            <div className="bg-white rounded-2xl p-8 shadow-sm">
              <h2 className="text-xl font-bold text-[#202124] mb-4">Wat is inbegrepen</h2>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {[
                  'Rondleiding',
                  'Bossche Bollen',
                  'Drankje',
                  'Verzekering'
                ].map((item, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <Check className="w-5 h-5 text-green-600" />
                    <span className="text-[#5f6368]">{item}</span>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Right Column - Booking Widget (Sticky) */}
          <div className="lg:col-span-1">
            <div className="sticky top-8 bg-white rounded-2xl p-6 shadow-lg">
              <div className="mb-6">
                <div className="text-sm text-[#5f6368] mb-1">Vanaf</div>
                <div className="text-3xl font-bold text-[#202124]">
                  €{product.price_pp || '12,50'}
                  <span className="text-base font-normal text-[#5f6368]"> per persoon</span>
                </div>
              </div>

              <div className="space-y-4">
                {/* Date Selector */}
                <div>
                  <label className="block text-sm font-semibold text-[#202124] mb-2">
                    Datum
                  </label>
                  <input
                    type="date"
                    value={selectedDate}
                    onChange={(e) => setSelectedDate(e.target.value)}
                    min={new Date().toISOString().split('T')[0]}
                    className="w-full px-4 py-3 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-[#d2e3fc] focus:border-transparent text-[#202124]"
                  />
                </div>

                {/* Time Slots */}
                <div>
                  <label className="block text-sm font-semibold text-[#202124] mb-2">
                    Starttijd
                  </label>
                  <div className="grid grid-cols-3 gap-2">
                    {['09:00', '11:00', '13:00', '15:00', '17:00', '19:00'].map((time) => (
                      <button
                        key={time}
                        onClick={() => setSelectedTime(time)}
                        className={`py-2 px-3 rounded-full text-sm font-medium transition-all ${
                          selectedTime === time
                            ? 'bg-[#343a40] text-white'
                            : 'bg-gray-100 text-[#5f6368] hover:bg-gray-200'
                        }`}
                      >
                        {time}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Participants */}
                <div>
                  <label className="block text-sm font-semibold text-[#202124] mb-2">
                    Aantal personen
                  </label>
                  <div className="flex items-center justify-between border border-gray-300 rounded-full px-4 py-3">
                    <button
                      onClick={() => setParticipants(Math.max(1, participants - 1))}
                      className="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-[#343a40] font-bold transition-colors"
                    >
                      −
                    </button>
                    <div className="flex items-center gap-2 text-[#202124]">
                      <Users className="w-5 h-5 text-[#5f6368]" />
                      <span className="font-semibold">{participants}</span>
                    </div>
                    <button
                      onClick={() => setParticipants(Math.min(12, participants + 1))}
                      className="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-[#343a40] font-bold transition-colors"
                    >
                      +
                    </button>
                  </div>
                </div>

                {/* Price Summary */}
                <div className="pt-4 border-t border-gray-200">
                  <div className="flex justify-between items-center mb-4">
                    <span className="text-[#5f6368]">Totaal</span>
                    <span className="text-2xl font-bold text-[#202124]">
                      €{calculatePrice()}
                    </span>
                  </div>
                </div>

                {/* Book Button */}
                <button
                  disabled={!selectedDate || !selectedTime}
                  className="w-full py-4 bg-[#343a40] text-white rounded-full font-semibold text-lg hover:bg-[#23272b] disabled:bg-gray-300 disabled:cursor-not-allowed transition-all hover:shadow-lg"
                >
                  Reserveren
                </button>

                <div className="flex items-center gap-2 justify-center text-sm text-[#5f6368]">
                  <Info className="w-4 h-4" />
                  <span>Gratis annuleren tot 24 uur voor aanvang</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
