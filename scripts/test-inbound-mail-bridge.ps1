param(
    [Parameter(Mandatory = $true)]
    [string]$BaseUrl,

    [Parameter(Mandatory = $true)]
    [string]$Secret,

    [string]$QuoteReference = '',

    [string]$RecipientDomain = 'dagjedenbosch.nl'
)

$endpoint = $BaseUrl.TrimEnd('/') + '/wp-json/bsp/v1/inbound-mail'

function Invoke-BridgeRequest {
    param(
        [string]$Name,
        [hashtable]$Body,
        [hashtable]$Headers = @{},
        [int[]]$ExpectedStatus = @(200)
    )

    try {
        $json = $Body | ConvertTo-Json -Depth 6
        $response = Invoke-WebRequest -Uri $endpoint -Method Post -ContentType 'application/json' -Headers $Headers -Body $json -SkipHttpErrorCheck
        $content = $response.Content
        $parsed = $null

        if ($content) {
            try {
                $parsed = $content | ConvertFrom-Json -Depth 10
            } catch {
                $parsed = $content
            }
        }

        $ok = $ExpectedStatus -contains [int]$response.StatusCode

        [pscustomobject]@{
            Scenario = $Name
            StatusCode = [int]$response.StatusCode
            Expected = ($ExpectedStatus -join ',')
            Passed = $ok
            Body = $parsed
        }
    } catch {
        [pscustomobject]@{
            Scenario = $Name
            StatusCode = -1
            Expected = ($ExpectedStatus -join ',')
            Passed = $false
            Body = $_.Exception.Message
        }
    }
}

$commonBody = @{
    from_email = 'klant@example.test'
    from_name = 'Klant Test'
    body = 'Wij willen graag een offerte en reageren via de inbound bridge.'
    provider_message_id = 'provider-' + [guid]::NewGuid().ToString()
    in_reply_to_message_id = ''
}

$results = @()

$results += Invoke-BridgeRequest -Name '403 without secret' -Body (@{
    to_email = "aanvragen@$RecipientDomain"
    subject = 'Nieuwe aanvraag zonder secret'
} + $commonBody) -ExpectedStatus @(403)

$results += Invoke-BridgeRequest -Name '422 invalid recipient' -Headers @{ 'X-BSP-Mail-Secret' = $Secret } -Body (@{
    to_email = 'wrong@example.test'
    subject = 'Nieuwe aanvraag met fout adres'
} + $commonBody) -ExpectedStatus @(422)

$results += Invoke-BridgeRequest -Name '201 create quote request' -Headers @{ 'X-BSP-Mail-Secret' = $Secret } -Body (@{
    to_email = "aanvragen@$RecipientDomain"
    subject = 'Nieuwe aanvraag zonder bestaande quote'
} + $commonBody) -ExpectedStatus @(201)

$results += Invoke-BridgeRequest -Name '202 log failure for info' -Headers @{ 'X-BSP-Mail-Secret' = $Secret } -Body (@{
    to_email = "info@$RecipientDomain"
    subject = 'Algemene vraag zonder match'
} + $commonBody) -ExpectedStatus @(202)

if ($QuoteReference.Trim() -ne '') {
    $results += Invoke-BridgeRequest -Name '201 matched quote by reference' -Headers @{ 'X-BSP-Mail-Secret' = $Secret } -Body (@{
        to_email = "aanvragen@$RecipientDomain"
        subject = "Reactie op offerte [$QuoteReference]"
    } + $commonBody) -ExpectedStatus @(201)
}

$results | Format-Table -AutoSize

$failed = @($results | Where-Object { -not $_.Passed })
if ($failed.Count -gt 0) {
    Write-Error ("Inbound bridge smoke test failed for: " + ($failed.Scenario -join ', '))
    exit 1
}

Write-Host 'Inbound bridge smoke test passed.' -ForegroundColor Green
