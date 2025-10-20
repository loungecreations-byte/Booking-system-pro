<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

/**
 * Lightweight directory to surface WooCommerce customer contact information
 * for the booking board. Falls back gracefully when WooCommerce is unavailable.
 */
final class CustomerDirectory
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 10): array
    {
        if (! function_exists('get_users') || ! class_exists('\WP_User_Query')) {
            return [];
        }

        $limit = max(1, $limit);
        $term  = trim($term);

        $args = [
            'number'     => $limit,
            'orderby'    => 'registered',
            'order'      => 'DESC',
            'role__in'   => ['customer', 'subscriber'],
            'fields'     => 'all_with_meta',
        ];

        if ($term !== '') {
            $args['search']         = '*' . $term . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query  = new \WP_User_Query($args);
        $users  = $query->get_results();
        $result = [];

        if (is_array($users)) {
            foreach ($users as $user) {
                if ($user instanceof \WP_User) {
                    $result[] = $this->buildCustomerFromUser($user);
                }
            }
        }

        return array_slice($result, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if (! function_exists('get_user_by')) {
            return null;
        }

        $user = get_user_by('ID', $id);
        if (! $user instanceof \WP_User) {
            return null;
        }

        return $this->buildCustomerFromUser($user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        if (! function_exists('get_user_by')) {
            return null;
        }

        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $user = get_user_by('email', $email);
        if (! $user instanceof \WP_User) {
            return null;
        }

        return $this->buildCustomerFromUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerFromUser(\WP_User $user): array
    {
        $firstName = $this->metaString($user->ID, 'billing_first_name');
        $lastName  = $this->metaString($user->ID, 'billing_last_name');
        $name      = trim($firstName . ' ' . $lastName);
        if ($name === '') {
            $name = $user->display_name ?: $user->user_login;
        }

        $billing  = $this->collectAddress($user->ID, 'billing');
        $shipping = $this->collectAddress($user->ID, 'shipping');
        $company  = $this->metaString($user->ID, 'billing_company');
        if ($company === '') {
            $company = $billing['company'];
        }

        $orders = [
            'count'            => 0,
            'last_order_id'    => null,
            'last_order_number'=> '',
            'last_order_total' => '',
            'last_order_date'  => '',
        ];

        if (function_exists('wc_get_customer_order_count')) {
            $orders['count'] = (int) wc_get_customer_order_count($user->ID);
        }

        if (function_exists('wc_get_customer_last_order')) {
            $order = wc_get_customer_last_order($user->ID);
            if (is_object($order)) {
                $orders['last_order_id'] = method_exists($order, 'get_id') ? (int) $order->get_id() : null;
                $orders['last_order_number'] = method_exists($order, 'get_order_number')
                    ? (string) $order->get_order_number()
                    : ($orders['last_order_id'] !== null ? (string) $orders['last_order_id'] : '');
                $orders['last_order_total'] = method_exists($order, 'get_formatted_order_total')
                    ? (string) $order->get_formatted_order_total()
                    : (method_exists($order, 'get_total') ? (string) $order->get_total() : '');

                if (method_exists($order, 'get_date_created')) {
                    $created = $order->get_date_created();
                    if (is_object($created) && method_exists($created, 'date')) {
                        $orders['last_order_date'] = $created->date('Y-m-d');
                    }
                }
            }
        }

        return [
            'id'       => (int) $user->ID,
            'name'     => $name,
            'email'    => $user->user_email,
            'phone'    => $this->metaString($user->ID, 'billing_phone'),
            'company'  => $company,
            'billing'  => $billing,
            'shipping' => $shipping,
            'orders'   => $orders,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function collectAddress(int $userId, string $type): array
    {
        $fields = [
            'company',
            'address_1',
            'address_2',
            'postcode',
            'city',
            'state',
            'country',
        ];

        $address = [];
        foreach ($fields as $field) {
            $metaKey = sprintf('%s_%s', $type, $field);
            $address[$field] = $this->metaString($userId, $metaKey);
        }

        $address['formatted'] = $this->formatAddress($address);

        return $address;
    }

    /**
     * @param array<string, string> $address
     */
    private function formatAddress(array $address): string
    {
        $parts = [];
        if ($address['company'] !== '') {
            $parts[] = $address['company'];
        }

        if ($address['address_1'] !== '') {
            $parts[] = $address['address_1'];
        }

        if ($address['address_2'] !== '') {
            $parts[] = $address['address_2'];
        }

        $cityLine = trim($address['postcode'] . ' ' . $address['city']);
        if ($cityLine !== '') {
            $parts[] = $cityLine;
        }

        if ($address['state'] !== '') {
            $parts[] = $address['state'];
        }

        if ($address['country'] !== '') {
            $parts[] = $address['country'];
        }

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function metaString(int $userId, string $key): string
    {
        if (! function_exists('get_user_meta')) {
            return '';
        }

        $value = get_user_meta($userId, $key, true);

        if (is_array($value)) {
            $value = reset($value);
        }

        return trim((string) $value);
    }
}
