# Developer Action & Filter Hooks

Trackly provides multiple action and filter hooks, allowing developers to extend, alter, or customize behavior across telemetry logging, proxy caching, and authentication.

---

## 🛠️ Filter Hooks

### 1. `trackly_trusted_proxies`
Allows developers to override or append custom CIDR ranges to the list of trusted reverse proxy IP subnets. Useful for enterprise networks, Varnish setups, or custom load balancer environments.

- **Parameters**: `array $trusted_proxies` (List of IP CIDR ranges).
- **Example Usage**:
```php
add_filter( 'trackly_trusted_proxies', function( $proxies ) {
    $proxies[] = '192.168.50.0/24'; // Trust custom corporate network
    return $proxies;
} );
```

### 2. `cron_schedules`
Standard WordPress filter used to register the `weekly` schedule interval required for Cloudflare IP updates.

- **Parameters**: `array $schedules`.
- **Note**: Handled internally by `ProxyRegistry::add_cron_intervals()`.

---

## ⚡ Action Hooks

### 1. `trackly_daily_cleanup`
Fires daily via WP-Cron to purge click records older than 30 days.

- **Trigger**: WP-Cron schedule worker.
- **Example hook extension**:
```php
add_action( 'trackly_daily_cleanup', function() {
    // Custom logging or cleanup tasks
    error_log( 'Trackly daily telemetry cleanup executed successfully.' );
} );
```

### 2. `trackly_weekly_ip_refresh`
Fires weekly via WP-Cron to execute asynchronous API fetches of Cloudflare's public IPv4/IPv6 range updates.

- **Trigger**: WP-Cron schedule worker.
