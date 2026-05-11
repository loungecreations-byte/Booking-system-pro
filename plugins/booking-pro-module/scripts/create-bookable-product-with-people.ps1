# ==========================================================
#  WooCommerce Bookable Product + People Types (PowerShell)
#  Compatible met Booking Pro / WooCommerce Bookings
# ==========================================================

# --- Productinstellingen ---
$ProductName = "E-Chopper Tour Den Bosch"
$Slug = "e-chopper-den-bosch"
$Description = "Ontdek Den Bosch op een E-chopper! Inclusief routekaart en helm."
$Price = 69.00

# --- Boekinginstellingen ---
$BookingDuration = 1
$BookingDurationUnit = "day"
$EnableCalendarRange = $true
$WholeDayBooking = $true
$MaxBookingsPerUnit = 5
$MinBookingDuration = 1
$MaxBookingDuration = 3
$MinAdvanceReservation = 0
$MaxAdvanceReservation = 180
$BufferDays = 0
$RequiresConfirmation = $false
$AllowCancellation = $true

# --- People instellingen ---
$EnablePeople = $true
$MinPeople = 1
$MaxPeople = 10
$CountPersonsAsBookings = $false
$EnablePersonTypes = $true

# --- Person Types array ---
$PersonTypes = @(
    @{ name = "Kind"; slug = "kind"; cost = 25.00; description = "Kinderen tot 12 jaar" },
    @{ name = "Volwassene"; slug = "volwassene"; cost = 69.00; description = "Volwassen deelnemers" },
    @{ name = "2 persoons kano"; slug = "2p-kano"; cost = 120.00; description = "Prijs per kano (2 personen)" }
)

# --- Product aanmaken ---
wp wc product create `
  --name="$ProductName" `
  --slug="$Slug" `
  --type="booking" `
  --regular_price=$Price `
  --virtual=true `
  --description="$Description" `
  --status="publish" | Out-Null

# --- Product-ID ophalen ---
$ProductID = wp wc product list --search="$ProductName" --format=json | ConvertFrom-Json | Select-Object -ExpandProperty id

# --- Basis boeking metavelden ---
wp post meta update $ProductID "_booking_duration" $BookingDuration
wp post meta update $ProductID "_booking_duration_unit" $BookingDurationUnit
wp post meta update $ProductID "_booking_enable_range_picker" $EnableCalendarRange
wp post meta update $ProductID "_booking_all_day" $WholeDayBooking
wp post meta update $ProductID "_booking_min_duration" $MinBookingDuration
wp post meta update $ProductID "_booking_max_duration" $MaxBookingDuration
wp post meta update $ProductID "_booking_min_date" $MinAdvanceReservation
wp post meta update $ProductID "_booking_max_date" $MaxAdvanceReservation
wp post meta update $ProductID "_booking_buffer" $BufferDays
wp post meta update $ProductID "_booking_requires_confirmation" $RequiresConfirmation
wp post meta update $ProductID "_booking_user_can_cancel" $AllowCancellation

# --- People instellingen ---
if ($EnablePeople) {
    wp post meta update $ProductID "_booking_has_persons" "yes"
    wp post meta update $ProductID "_booking_min_persons" $MinPeople
    wp post meta update $ProductID "_booking_max_persons" $MaxPeople
    wp post meta update $ProductID "_booking_persons_count_as_separate_bookings" $CountPersonsAsBookings
    wp post meta update $ProductID "_booking_person_types_enabled" $EnablePersonTypes
}

# --- Person Types toevoegen ---
if ($EnablePersonTypes -and $PersonTypes.Count -gt 0) {
    foreach ($person in $PersonTypes) {
        $PersonTypeID = wp post create `
          --post_type="bookable_person" `
          --post_title=$person.name `
          --post_status="publish" `
          --post_parent=$ProductID `
          --porcelain

        wp post meta update $PersonTypeID "_person_type_base_cost" $person.cost
        wp post meta update $PersonTypeID "_person_type_description" $person.description
        wp post meta update $PersonTypeID "_person_type_slug" $person.slug

        Write-Host "👤 Persoonstype toegevoegd: $($person.name) (€$($person.cost))"
    }
}

Write-Host "✅ Boekbaar product '$ProductName' met personen succesvol aangemaakt (ID: $ProductID)"
