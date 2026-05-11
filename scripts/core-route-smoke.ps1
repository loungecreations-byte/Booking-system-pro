$ErrorActionPreference = 'Stop'

$baseUrl = 'http://dagjedenbosch.local'
$timestamp = [int][double]::Parse((Get-Date -UFormat %s))

$routes = @(
    @{ Path = '/'; ExpectedCanonical = "$baseUrl/" },
    @{ Path = '/spots/'; ExpectedCanonical = "$baseUrl/spots/" },
    @{ Path = '/activiteiten/'; ExpectedCanonical = "$baseUrl/activiteiten/" },
    @{ Path = '/plan-je-dag/'; ExpectedCanonical = "$baseUrl/plan-je-dag/" }
)

$commerceRoutes = @(
    @{ Path = '/product/jeroen-bosch-tour/'; Type = 'product' },
    @{ Path = '/cart/'; Type = 'cart' },
    @{ Path = '/checkout/'; Type = 'checkout' }
)

$failures = @()
$results = @()

foreach ($route in $routes) {
    $url = '{0}{1}?dbg={2}' -f $baseUrl, $route.Path, $timestamp
    $response = Invoke-WebRequest -UseBasicParsing -Headers @{ 'Cache-Control' = 'no-cache' } -Uri $url -TimeoutSec 30
    $html = $response.Content

    $h1Match = [regex]::Match($html, '<h1\b[^>]*>(.*?)</h1>', 'Singleline,IgnoreCase')
    $canonicalMatch = [regex]::Match($html, '<link[^>]+rel=["''][^"'']*canonical[^"'']*["''][^>]+href=["'']([^"'']+)["'']', 'IgnoreCase')
    $mainPresent = [regex]::IsMatch($html, '<main\b', 'IgnoreCase')
    $h1Present = $h1Match.Success
    $canonical = if ($canonicalMatch.Success) { $canonicalMatch.Groups[1].Value } else { '' }
    $h1Text = if ($h1Present) { ($h1Match.Groups[1].Value -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim() } else { '' }
    $coreUiCssPresent = [regex]::IsMatch($html, 'ddb-core-ui/assets/css/design-system\.css', 'IgnoreCase')
    $coreUiJsPresent = [regex]::IsMatch($html, 'ui-interactions\.js', 'IgnoreCase')
    $antiFoucPresent = [regex]::IsMatch($html, 'id=["'']ddb-anti-fouc["'']', 'IgnoreCase')

    $results += [pscustomobject]@{
        Path = $route.Path
        Status = $response.StatusCode
        Main = $mainPresent
        H1 = $h1Present
        H1Text = $h1Text
        Canonical = $canonical
        CoreUiCss = $coreUiCssPresent
        CoreUiJs = $coreUiJsPresent
        AntiFouc = $antiFoucPresent
    }

    if ($response.StatusCode -ne 200) {
        $failures += "$($route.Path): HTTP $($response.StatusCode)"
    }
    if (-not $mainPresent) {
        $failures += "$($route.Path): missing <main>"
    }
    if (-not $h1Present) {
        $failures += "$($route.Path): missing <h1>"
    }
    if ($canonical -ne $route.ExpectedCanonical) {
        $failures += "$($route.Path): canonical mismatch ($canonical)"
    }
}

$results | Format-Table -AutoSize

$commerceResults = @()

foreach ($route in $commerceRoutes) {
    $url = '{0}{1}?dbg={2}' -f $baseUrl, $route.Path, $timestamp
    $response = Invoke-WebRequest -UseBasicParsing -Headers @{ 'Cache-Control' = 'no-cache' } -Uri $url -TimeoutSec 30
    $html = $response.Content

    $bookingFormPresent = [regex]::IsMatch($html, 'id=["'']sbdp-booking-form["'']', 'IgnoreCase')
    $activityCtaAssetsPresent = [regex]::IsMatch($html, 'id=["'']ddb-cta-block-(css|js|js-extra)["'']|activity-cta-block\.css|activity-cta-block\.js', 'IgnoreCase')
    $summaryAssetsPresent = [regex]::IsMatch($html, 'product-summary\.css|product-summary\.js|class=["'']sbdp-sticky-cta["'']', 'IgnoreCase')

    $commerceResults += [pscustomobject]@{
        Path = $route.Path
        Status = $response.StatusCode
        ProductForm = if ($route.Type -eq 'product') { $bookingFormPresent } else { $null }
        ActivityCtaAssets = if ($route.Type -eq 'product') { $activityCtaAssetsPresent } else { $null }
        SummaryAssets = $summaryAssetsPresent
    }

    if ($response.StatusCode -ne 200) {
        $failures += "$($route.Path): HTTP $($response.StatusCode)"
    }

    if ($route.Type -eq 'product' -and -not $bookingFormPresent) {
        $failures += "$($route.Path): missing booking form"
    }
    if ($route.Type -eq 'product' -and $activityCtaAssetsPresent) {
        $failures += "$($route.Path): unexpected activity CTA assets"
    }
    if ($summaryAssetsPresent) {
        $failures += "$($route.Path): unexpected product summary assets"
    }
}

if ($commerceResults.Count -gt 0) {
    Write-Host ''
    $commerceResults | Format-Table -AutoSize
}

$cartFlowResults = @()

try {
    $productSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $productResponse = Invoke-WebRequest -UseBasicParsing -Headers @{ 'Cache-Control' = 'no-cache' } -Uri ("{0}/product/jeroen-bosch-tour/?dbg={1}" -f $baseUrl, $timestamp) -WebSession $productSession -TimeoutSec 30
    $productHtml = $productResponse.Content
    $bookingNonceMatch = [regex]::Match($productHtml, 'name=["'']sbdp_booking_nonce["'']\s+value=["'']([^"'']+)["'']', 'IgnoreCase')
    $productIdMatch = [regex]::Match($productHtml, 'name=["'']add-to-cart["'']\s+value=["'']([^"'']+)["'']', 'IgnoreCase')

    if (-not $bookingNonceMatch.Success -or -not $productIdMatch.Success) {
        throw 'Could not resolve legacy planner nonce or product id.'
    }

    $bookingForm = @{
        'sbdp_booking_nonce' = $bookingNonceMatch.Groups[1].Value
        'add-to-cart'        = $productIdMatch.Groups[1].Value
        'sbdp_date'          = '2026-03-25'
        'sbdp_time'          = '10:00'
        'sbdp_participants'  = '2'
        'sbdp_add_to_cart'   = 'Leg in winkelwagen'
    }

    Invoke-WebRequest -UseBasicParsing -Uri ("{0}/cart/?dbg={1}" -f $baseUrl, $timestamp) -Method Post -Body $bookingForm -WebSession $productSession -MaximumRedirection 5 -TimeoutSec 30 | Out-Null
    $cartState = Invoke-RestMethod -Uri ("{0}/wp-json/wc/store/v1/cart?dbg={1}" -f $baseUrl, $timestamp) -WebSession $productSession -TimeoutSec 30

    $cartItems = @($cartState.items)
    $firstItem = if ($cartItems.Count -gt 0) { $cartItems[0] } else { $null }
    $itemData = if ($firstItem -and $firstItem.item_data) { @($firstItem.item_data) } else { @() }
    $participantsValue = ($itemData | Where-Object { $_.name -eq 'Deelnemers' } | Select-Object -First 1).value
    $dateValue = ($itemData | Where-Object { $_.name -eq 'Datum' } | Select-Object -First 1).value
    $timeValue = ($itemData | Where-Object { $_.name -eq 'Tijd' } | Select-Object -First 1).value
    $itemsCount = if ($null -ne $cartState.items_count) { [int] $cartState.items_count } else { 0 }

    $cartFlowResults += [pscustomobject]@{
        Flow = 'legacy_product_to_cart'
        ItemsCount = $itemsCount
        Participants = $participantsValue
        Date = $dateValue
        Time = $timeValue
    }

    if ($itemsCount -ne 2) {
        $failures += "legacy product cart flow: expected items_count=2, got $itemsCount"
    }
    if ($participantsValue -ne '2') {
        $failures += "legacy product cart flow: expected deelnemers=2, got '$participantsValue'"
    }
    if ($dateValue -ne '2026-03-25') {
        $failures += "legacy product cart flow: expected datum 2026-03-25, got '$dateValue'"
    }
    if ($timeValue -ne '10:00 - 11:00') {
        $failures += "legacy product cart flow: expected tijd 10:00 - 11:00, got '$timeValue'"
    }
} catch {
    $failures += "legacy product cart flow: $($_.Exception.Message)"
}

try {
    $aggregateSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $aggregateResponse = Invoke-WebRequest -UseBasicParsing -Headers @{ 'Cache-Control' = 'no-cache' } -Uri ("{0}/product/bierproeverij/?dbg={1}" -f $baseUrl, $timestamp) -WebSession $aggregateSession -TimeoutSec 30
    $aggregateHtml = $aggregateResponse.Content
    $aggregateNonceMatch = [regex]::Match($aggregateHtml, 'name=["'']sbdp_booking_nonce["'']\s+value=["'']([^"'']+)["'']', 'IgnoreCase')
    $aggregateProductMatch = [regex]::Match($aggregateHtml, 'name=["'']add-to-cart["'']\s+value=["'']([^"'']+)["'']', 'IgnoreCase')

    if (-not $aggregateNonceMatch.Success -or -not $aggregateProductMatch.Success) {
        throw 'Could not resolve aggregate planner nonce or product id.'
    }

    $aggregateForm = @{
        'sbdp_booking_nonce' = $aggregateNonceMatch.Groups[1].Value
        'add-to-cart'        = $aggregateProductMatch.Groups[1].Value
        'sbdp_date'          = '2026-03-25'
        'sbdp_time'          = '10:00'
        'sbdp_participants'  = '10'
        'sbdp_combi_ids[]'   = '350'
        'sbdp_add_to_cart'   = 'Leg in winkelwagen'
    }
    $aggregateForm['sbdp_combi_timing[350]'] = 'before'

    Invoke-WebRequest -UseBasicParsing -Uri ("{0}/product/bierproeverij/?dbg={1}" -f $baseUrl, $timestamp) -Method Post -Body $aggregateForm -WebSession $aggregateSession -MaximumRedirection 5 -TimeoutSec 30 | Out-Null
    $aggregateCartState = Invoke-RestMethod -Uri ("{0}/wp-json/wc/store/v1/cart?dbg={1}" -f $baseUrl, $timestamp) -WebSession $aggregateSession -TimeoutSec 30

    $aggregateItems = @($aggregateCartState.items)
    $aggregateItem = if ($aggregateItems.Count -gt 0) { $aggregateItems[0] } else { $null }
    $aggregateItemData = if ($aggregateItem -and $aggregateItem.item_data) { @($aggregateItem.item_data) } else { @() }
    $aggregateParticipants = ($aggregateItemData | Where-Object { $_.name -eq 'Deelnemers' } | Select-Object -First 1).value
    $aggregateDate = ($aggregateItemData | Where-Object { $_.name -eq 'Datum' } | Select-Object -First 1).value
    $aggregateTime = ($aggregateItemData | Where-Object { $_.name -eq 'Tijd' } | Select-Object -First 1).value
    $aggregateArrangement = ($aggregateItemData | Where-Object { $_.name -eq 'Arrangement totaal' } | Select-Object -First 1).value
    $aggregateItemsCount = if ($null -ne $aggregateCartState.items_count) { [int] $aggregateCartState.items_count } else { 0 }
    $aggregateLinePrice = if ($aggregateItem -and $aggregateItem.prices) { [string] $aggregateItem.prices.price } else { '' }
    $aggregateCartTotal = if ($aggregateCartState.totals) { [string] $aggregateCartState.totals.total_price } else { '' }

    $cartFlowResults += [pscustomobject]@{
        Flow = 'aggregate_product_to_cart'
        ItemsCount = $aggregateItemsCount
        Participants = $aggregateParticipants
        Date = $aggregateDate
        Time = $aggregateTime
    }

    if ($aggregateItemsCount -ne 10) {
        $failures += "aggregate product cart flow: expected items_count=10, got $aggregateItemsCount"
    }
    if ($aggregateParticipants -ne '10') {
        $failures += "aggregate product cart flow: expected deelnemers=10, got '$aggregateParticipants'"
    }
    if ($aggregateDate -ne '2026-03-25') {
        $failures += "aggregate product cart flow: expected datum 2026-03-25, got '$aggregateDate'"
    }
    if ($aggregateTime -ne '09:30 - 11:00') {
        $failures += "aggregate product cart flow: expected tijd 09:30 - 11:00, got '$aggregateTime'"
    }
    if ($aggregateLinePrice -ne '3200') {
        $failures += "aggregate product cart flow: expected line price 3200, got '$aggregateLinePrice'"
    }
    if ($aggregateCartTotal -ne '32000') {
        $failures += "aggregate product cart flow: expected cart total 32000, got '$aggregateCartTotal'"
    }
    if ($aggregateArrangement -notmatch '320,00') {
        $failures += "aggregate product cart flow: expected arrangement totaal 320,00, got '$aggregateArrangement'"
    }
} catch {
    $failures += "aggregate product cart flow: $($_.Exception.Message)"
}

if ($cartFlowResults.Count -gt 0) {
    Write-Host ''
    $cartFlowResults | Format-Table -AutoSize
}

if ($failures.Count -gt 0) {
    Write-Host ''
    Write-Host 'Smoke check failed:' -ForegroundColor Red
    $failures | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
    exit 1
}

Write-Host ''
Write-Host 'Smoke check passed.' -ForegroundColor Green
