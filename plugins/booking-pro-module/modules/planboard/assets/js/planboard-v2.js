/* global SBDP_PLANBOARD */
(function () {
    if (!window || !document) {
        return;
    }

    var root = document.getElementById('sbdp-planboard-v2-root');
    if (!root) {
        return;
    }

    root.innerHTML = '' +
        '<div class="sbdp-planboard-v2-shell">' +
        '<h1>Planboard v2</h1>' +
        '<p class="sbdp-planboard-v2-note">TODO:PROJECT_SPECIFIC - inject Planboard UI here.</p>' +
        '<div class="sbdp-planboard-v2-meta">' +
        '<span>REST:</span>' +
        '<code>' + (SBDP_PLANBOARD && SBDP_PLANBOARD.restBase ? SBDP_PLANBOARD.restBase : '/wp-json/bsp/v2/planboard') + '</code>' +
        '</div>' +
        '</div>';
})();
