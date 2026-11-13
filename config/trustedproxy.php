<?php

return [
    // Proxies whose X-Forwarded-* headers are trusted: addresses or CIDR ranges,
    // comma-separated without spaces, or REMOTE_ADDR to trust only the machine
    // that connects to the app (a load balancer whose address changes; the app
    // must then be reachable only through it). Never '*': it trusts every
    // address, so a client could again choose its own IP. The empty default
    // trusts none, which also turns off Laravel's automatic trust for hosts
    // ending in .on-forge.com or .on-vapor.com, and on Laravel Cloud; a forged
    // Host header could otherwise claim the first two.
    'proxies' => env('TRUSTED_PROXIES') ?: [],
];
