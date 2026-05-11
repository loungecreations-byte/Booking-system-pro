=== DDB Spinwheel (Daily Spin) ===
Contributors: owncreations
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Daily spinwheel rewards (kortingen/tegoed/berichten).
Shortcode: [ddb_spinwheel]

== Installatie ==
1) Upload en activeer de plugin.
2) Ga naar Admin → DDB Spinwheel (menu links).
3) Pas prijzen/weights aan.
4) Plaats [ddb_spinwheel] op een pagina (home/planner/bedanktpagina).

== REST API ==
GET  /wp-json/ddb-spin/v1/status
POST /wp-json/ddb-spin/v1/execute
POST /wp-json/ddb-spin/v1/earn        (spins verdienen via review/referral/check-in/boeking)
POST /wp-json/ddb-spin/v1/partner/redeem (partner markeert coupon als ingewisseld)

Voorbeeld (curl):
curl -X POST -H "X-WP-Nonce: <nonce>" -H "Content-Type: application/json" -d "{}" https://example.com/wp-json/ddb-spin/v1/execute
curl -X POST -H "X-WP-Nonce: <nonce>" -H "Content-Type: application/json" -d "{\"action\":\"review\"}" https://example.com/wp-json/ddb-spin/v1/earn
curl -X POST -H "Content-Type: application/json" -d "{\"token\":\"<partner_token>\",\"coupon_code\":\"DDB-XXXX\"}" https://example.com/wp-json/ddb-spin/v1/partner/redeem

== WooCommerce ==
Als WooCommerce actief is en je gebruikt prize type "coupon", dan maakt de plugin een one-time coupon aan (7 dagen geldig).
Auto-apply (instelbaar) zet de gewonnen coupon automatisch in cart/checkout.
