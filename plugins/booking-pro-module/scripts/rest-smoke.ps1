param(
    [string]$BaseUrl = 'https://site.local/wp-json',
    [int]$QuoteProductId = 0,
    [switch]$SkipChannels
)

$ErrorActionPreference = 'Stop'

function Invoke-Smoke([string]$Path, [string]$Method = 'GET', [object]$Body = $null) {
    $trimmedBase = $BaseUrl.TrimEnd('/')
    $url = "$trimmedBase$Path"
    Write-Host "Checking $url ($Method)" -ForegroundColor Cyan

    if ($Method -eq 'GET') {
        $response = Invoke-WebRequest -Uri $url -UseBasicParsing -Method Get -ErrorAction Stop
    } else {
        $json = $Body ? ($Body | ConvertTo-Json -Depth 5) : '{}'
        $response = Invoke-WebRequest -Uri $url -UseBasicParsing -Method Post -Body $json -ContentType 'application/json' -ErrorAction Stop
    }

    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
        throw "Smoke test failed for $url (HTTP $($response.StatusCode))"
    }

    Write-Host "? $url responded with $($response.StatusCode)" -ForegroundColor Green
}

Invoke-Smoke '/sbdp/v1/services'

if (-not $SkipChannels) {
    Invoke-Smoke '/bsp/v1/channels'
}

if ($QuoteProductId -gt 0) {
    Invoke-Smoke '/bsp/v1/pricing/quote' 'POST' @{ product_id = $QuoteProductId }
}

Write-Host 'REST smoke tests completed.' -ForegroundColor Green