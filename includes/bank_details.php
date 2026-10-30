<?php
// includes/bank_details.php
// Editable bank-transfer + store pickup details shown during checkout.
// IMPORTANT: Replace the placeholder account numbers/names with the real ones.

function bankAccountDetails(): array {
    return [
        [
            'bank'      => 'UnionBank of the Philippines',
            'account_name' => 'ShuttleSync Business',
            'account_number' => '0000-0000-0000',
            'type'      => 'Savings',
        ],
        [
            'bank'      => 'China Bank',
            'account_name' => 'ShuttleSync Business',
            'account_number' => '0000-0000-0000',
            'type'      => 'Savings',
        ],
    ];
}

function storePickupInfo(): array {
    return [
        'name'         => 'Hi-Power Badminton Court',
        'address'      => '8000 BBL Compound, Nat\'l Highway, Canlalay, Biñan, Laguna (Landmark: MFS Wheels)',
        'hours'        => 'Mon–Sun, 9:00 AM – 10:00 PM',
        'requirements' => 'Bring your Order/Payment Reference and a valid government-issued ID (e.g., national ID, driver\'s license) when claiming.',
    ];
}

function generatePaymentReference(): string {
    return 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}