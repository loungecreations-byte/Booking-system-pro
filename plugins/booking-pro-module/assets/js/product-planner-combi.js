document.addEventListener('DOMContentLoaded', function () {
  var grids = document.querySelectorAll('.ddb-combi-grid');

  grids.forEach(function (grid) {
    var cards = grid.querySelectorAll('.ddb-combi-card[data-val]');
    var form = grid.closest('form');
    var combiInput = form ? form.querySelector('#sbdp_combi') : null;

    if (!cards.length || !combiInput) {
      return;
    }

    var setActiveCard = function (activeCard) {
      cards.forEach(function (card) {
        card.classList.toggle('is-selected', card === activeCard);
      });

      combiInput.value = activeCard ? activeCard.getAttribute('data-val') || '' : '';
    };

    cards.forEach(function (card) {
      card.addEventListener('click', function () {
        setActiveCard(card);
      });
    });

    var selectedCard = grid.querySelector('.ddb-combi-card.is-selected[data-val]');
    setActiveCard(selectedCard || cards[0]);
  });
});
